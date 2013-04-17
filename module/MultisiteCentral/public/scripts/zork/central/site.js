/**
 * Central-site functionalities
 * @package zork
 * @subpackage central
 * @author David Pozsar <david.pozsar@megaweb.hu>
 */
( function ( global, $, js )
{
    "use strict";

    if ( typeof js.central.site !== "undefined" )
    {
        return;
    }

    var wizard  = js.require( "js.wizard" ),
        message = js.require( "js.ui.message" );

    /**
     * @class CentralSite module
     * @constructor
     * @memberOf Zork
     */
    global.Zork.Central.Site = function ()
    {
        this.version = "1.0";
        this.modulePrefix = [ "zork", "central", "site" ];
    };

    global.Zork.Central.prototype.site = new global.Zork.Central.Site();

    global.Zork.Central.Site.prototype.create = function ( element )
    {
        element     = $( element );
        var form    = element.find( "form:first" );

        if ( form.length ) {
            form.find( ":input[name='cancel']" )
                .remove();

            form.submit( function () {
                wizard( {
                    "url"   : form.attr( "action" ),
                    "form"  : form,
                    "cancel": function ( cancel ) {
                        cancel = $( cancel );

                        message( {
                            "title": cancel.attr( "title" ),
                            "message": cancel.text()
                        } );
                    },
                    "finish": function ( finish ) {
                        finish = $( finish );

                        message( {
                            "title": finish.attr( "title" ),
                            "message": finish.html(),
                            "important": true
                        } );
                    }
                } );
            } );
        }
    };

    global.Zork.Central.Site.prototype.create.isElementConstructor = true;

} ( window, jQuery, zork ) );