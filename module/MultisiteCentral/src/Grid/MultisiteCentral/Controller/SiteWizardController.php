<?php

namespace Grid\MultisiteCentral\Controller;

use Zend\Form\Form;
use Grid\Core\View\Model\WizardStep;
use Zend\Mvc\Exception\RuntimeException;
use Grid\Core\Controller\AbstractWizardController;
use Zend\Authentication\AuthenticationService;

/**
 * SiteWizardController
 *
 * @author David Pozsar <david.pozsar@megaweb.hu>
 */
class SiteWizardController extends AbstractWizardController
{

    /**
     * @const string
     */
    const CLONE_FROM = '_central';

    /**
     * Get step model
     *
     * @param string $step
     * @return \Core\View\Model\WizardStep
     */
    protected function getStep( $step )
    {
        $model = new WizardStep( array(
            'textDomain' => 'central',
        ) );

        switch ( $step )
        {
            case $this->startStep:

                $model->setOptions( array(
                    'finish'    => false,
                    'next'      => 'layout',
                ) );

                break;

            case 'layout':

                $settings = $this->getStepStore( 'settings' );

                if ( empty( $settings ) )
                {
                    $start = $this->getStepStore( $this->startStep );

                    $this->setStepStore( 'settings', array(
                        'headTitle'     => $start['subdomain'],
                        'keywords'      => $start['subdomain'],
                        'description'   => $start['subdomain'],
                    ) );
                }

                $model->setOptions( array(
                    'finish'    => false,
                    'next'      => 'content',
                ) );

                break;

            case 'content':

                $model->setOptions( array(
                    'finish'    => true,
                    'next'      => 'settings',
                ) );

                break;

            case 'settings':

                $model->setOptions( array(
                    'finish'    => true,
                    'next'      => 'check',
                ) );

                break;

            case 'check':

                $postfix = $this->getServiceLocator()
                                ->get( 'Config' )
                                     [ 'modules' ]
                                     [ 'Grid\MultisiteCentral' ]
                                     [ 'domainPostfix' ];

                $model->setVariable( 'stores', $this->getStepStores( false ) )
                      ->setVariable( 'domainPostfix', $postfix )
                      ->setDescriptionPartial( 'grid/multisite-central/site-wizard/check' )
                      ->setOptions( array(
                            'finish'    => true,
                            'next'      => 'finish',
                        ) );

                break;

            default:

                throw new RuntimeException(
                    'Step: ' . $step . ' is not supported'
                );
        }

        if ( $step == 'check' )
        {
            $model->setStepForm( new Form );
        }
        else
        {

            $model->setDescriptionPartial(
                        'grid/multisite-central/site-wizard/description'
                    )
                  ->setStepForm(
                        $this->getServiceLocator()
                             ->get( 'Form' )
                             ->get( 'Grid\MultisiteCentral\\SiteWizard\\' .
                                    ucfirst( $step ) )
                    );
        }

        return $model;
    }

    /**
     * Step action
     */
    public function stepAction()
    {
        if ( ! $this->getServiceLocator()
                    ->get( 'Grid\User\Model\Permissions\Model' )
                    ->isAllowed( 'central.site', 'create' ) )
        {
            $this->getResponse()
                 ->setStatusCode( 403 );

            return;
        }

        return parent::stepAction();
    }

    /**
     * Cancel action
     */
    public function cancelAction()
    {
        // do nothing special
    }

    /**
     * Finish action
     */
    public function finishAction()
    {
        $auth           = new AuthenticationService;
        $startData      = $this->getStepStore( 'start' );
        $settingsData   = $this->getStepStore( 'settings' );
        $layoutData     = $this->getStepStore( 'layout' );
        $contentData    = $this->getStepStore( 'content' );
        $moduleData     = $this->getServiceLocator()
                               ->get( 'Config' )
                                    [ 'modules' ]
                                    [ 'Grid\MultisiteCentral' ];

        // generate user if nececarry

        if ( $auth->hasIdentity() )
        {
            $user       = $auth->getIdentity();
            $userId     = $user->id;
        }
        else
        {
            $userModel  = $this->getServiceLocator()
                               ->get( 'Grid\User\Model\User\Model' );

            $user = $userModel->register( array(
                'email'         => $startData['email'],
                'displayName'   => $startData['displayName'],
                'locale'        => (string) $this->locale(),
                'confirmed'     => true,
            ) );

            if ( empty( $user ) )
            {
                return array(
                    'error'     => true,
                    'message'   => 'central.site.create.error.user',
                );
            }
            else
            {
                $userId = $user->id;

                $this->getServiceLocator()
                     ->get( 'Grid\Mail\Model\Template\Sender' )
                     ->prepare( array(
                         'template' => 'user.register',
                         'locale'   => $user->locale,
                     ) )
                     ->send( array(
                         'email'        => $user->email,
                         'display_name' => $user->displayName,
                         'confirm_url'  => '',
                     ), array(
                         $user->email   => $user->displayName,
                     ) );
            }
        }

        // schema name

        $schemaName = $moduleData['schemaPrefix'] .
                      $startData['subdomain'] .
                      $moduleData['schemaPostfix'];

        // domain name

        $domainName = $startData['subdomain'] .
                      $moduleData['domainPostfix'];

        // generate site (schema)

        $siteModel  = $this->getServiceLocator()
                           ->get( 'Grid\MultisiteCentral\Model\Site\Model' );

        $site = $siteModel->create( array(
            'schema'    => $schemaName,
            'ownerId'   => $userId,
            'domains'   => array( $domainName )
        ) );

        if ( ! $site->save() )
        {
            return array(
                'error'     => true,
                'message'   => 'central.site.create.error.schema',
            );
        }

        // save the default domain

        $domainModel = $this->getServiceLocator()
                            ->get( 'Grid\MultisitePlatform\Model\Domain\Model' );

        $domain = $domainModel->create( array(
            'domain'    => $domainName,
            'siteId'    => $site->id,
        ) );

        if ( ! $domain->save() )
        {
            return array(
                'error'     => true,
                'message'   => 'central.site.create.error.domain',
            );
        }

        // setup settings

        $locale = empty( $contentData['locale'] )
            ? (string) $this->locale()
            : $contentData['locale'];

        $settingsModel = clone $this->getServiceLocator()
                                    ->get( 'Grid\Core\Model\Settings\Model' );

        $settingsModel->getMapper()
                      ->setDbSchema( $schemaName );

        $definition = $settingsModel->find( 'site-definition' );
        $definition->setSettings( $settingsData );

        if ( ! $definition->save() )
        {
            return array(
                'error'     => true,
                'message'   => 'central.site.create.error.settings',
            );
        }

        $locales = $settingsModel->find( 'locale' );
        $locales->setSettings( array(
            'default'   => $locale,
            'enabled'   => array_unique( array( $locale, 'en' ) ),
        ) );

        if ( ! $locales->save() )
        {
            return array(
                'error'     => true,
                'message'   => 'central.site.create.error.settings',
            );
        }

        // create default layout

        $paragraphModel = clone $this->getServiceLocator()
                                     ->get( 'Grid\Paragraph\Model\Paragraph\Model' );

        $paragraphModel->getMapper()
                       ->setDbSchema( $schemaName );

        $layout   = $layoutData['layout'];
        $layoutId = $paragraphModel->cloneFrom( $layout, static::CLONE_FROM );

        if ( empty( $layoutId ) )
        {
            return array(
                'error'     => true,
                'message'   => 'central.site.create.error.layout',
            );
        }

        // create default content

        $content   = $contentData['content'];
        $contentId = $paragraphModel->cloneFrom( $content, static::CLONE_FROM );

        if ( empty( $contentId ) )
        {
            return array(
                'error'     => true,
                'message'   => 'central.site.create.error.content',
            );
        }

        if ( ! empty( $contentData['title'] ) )
        {
            $structure = $paragraphModel->find( $contentId );

            if ( ! empty( $structure ) )
            {
                $structure->title = trim( $contentData['title'] );
                $structure->save();
            }
        }

        // create default subdomain

        $subdomainModel = clone $this->getServiceLocator()
                                     ->get( 'Grid\Core\Model\SubDomain\Model' );

        $subdomainModel->getMapper()
                       ->setDbSchema( $schemaName );

        $subdomain = $subdomainModel->create( array(
            'subdomain'         => '',
            'locale'            => $locale,
            'defaultLayoutId'   => $layoutId,
            'defaultContentId'  => $contentId,
        ) );

        if ( ! $subdomain->save() )
        {
            return array(
                'error'     => true,
                'message'   => 'central.site.create.error.subdomain',
            );
        }

        // create seo-friendly url for the default content

        if ( ! empty( $contentData['title'] ) )
        {
            $uriModel = clone $this->getServiceLocator()
                                   ->get( 'Grid\Core\Model\Uri\Model' );

            $uriModel->getMapper()
                     ->setDbSchema( $schemaName );

            $uri = $uriModel->create( array(
                'subdomainId'   => $subdomain->id,
                'contentId'     => $contentId,
                'locale'        => $locale,
                'uri'           => preg_replace(
                    '/\\s+/', '-',
                    mb_strtolower( $contentData['title'], 'UTF-8' )
                ),
            ) );

            $uri->save();
        }

        // insert content into the menu

        if ( ! empty( $contentData['title'] ) )
        {
            $menuModel = clone $this->getServiceLocator()
                                    ->get( 'Grid\Menu\Model\Menu\Model' );

            $menuModel->getMapper()
                      ->setDbSchema( $schemaName );

            $menu = $menuModel->create( array(
                'type'      => 'content',
                'label'     => $contentData['title'],
                'contentId' => $contentId,
            ) );

            if ( ! $menu->save() )
            {
                return array(
                    'error'     => true,
                    'message'   => 'central.site.create.error.menu',
                );
            }

            // move the newly created menu-item to the first menu (if exists)

            $firstMenu = $menuModel->findFirst();

            if ( ! empty( $firstMenu ) && $firstMenu->id != $menu->id )
            {
                $menuModel->appendTo( $menu->id, $firstMenu->id );
            }
        }

        // create dirs in uploads

        $sep     = DIRECTORY_SEPARATOR;
        $base    = '.' . $sep . 'public' . $sep . 'uploads' . $sep;
        $uploads = $base . $schemaName;
        $files   = $base . self::CLONE_FROM;

        @ mkdir( $uploads, 0777, true );

        if ( ! empty( $moduleData['uploadsDirs'] ) )
        {
            foreach ( $moduleData['uploadsDirs'] as $dir )
            {
                @ mkdir( $uploads . $sep . trim( $dir, '/' . $sep ), 0777, true );
            }
        }

        // copy files to uploads

        if ( ! empty( $moduleData['uploadsFiles'] ) )
        {
            foreach ( $moduleData['uploadsFiles'] as $file )
            {
                if ( is_file( $files . $sep . $file ) )
                {
                    @ copy( $files . $sep . $file, $uploads . $sep . $file );
                }
            }
        }

        // view settings

        return array(
            'domain'    => $domainName,
            'url'       => $this->url()
                                ->fromRoute( 'Grid\MultisitePlatform\AutoLogin\ByDomain', array(
                                    'locale'    => (string) $this->locale(),
                                    'domain'    => $domainName,
                                ) ),
        );
    }

}