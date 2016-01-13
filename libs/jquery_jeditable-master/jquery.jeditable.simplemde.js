/*
 * Charcounter textarea for Jeditable
 *
 * Copyright (c) 2008 Mika Tuupola
 *
 * Licensed under the MIT license:
 *   http://www.opensource.org/licenses/mit-license.php
 * 
 * Depends on Charcounter jQuery plugin by Tom Deater
 *   http://www.tomdeater.com/jquery/character_counter/
 *
 * Project home:
 *   http://www.appelsiini.net/projects/jeditable
 *
 * Revision: $Id: jquery.jeditable.autogrow.js 344 2008-03-24 16:02:11Z tuupola $
 *
 */
var registermdes = [];
$.editable.addInputType('simplemde', {
    element : function(settings, original) {

        var textarea = $('<textarea />');
        if (settings.rows) {
            textarea.attr('rows', settings.rows);
        } else {
            textarea.height(settings.height);
        }
        if (settings.cols) {
            textarea.attr('cols', settings.cols);
        } else {
            textarea.width(settings.width);
        }
        $(this).append(textarea);

        return(textarea);
    },
    plugin : function(settings, original) {

        for(i in registermdes){
            if(i != $(original).attr('id')){
//                console.log(i);
//                console.log(registermdes[i].element.parentNode.parentNode);
//                console.log($(registermdes[i].element.parentNode.parentNode).find('form button[type=cancel]'));
                $(registermdes[i].element.parentNode.parentNode).find('form button[type=cancel]').click();
            }
        }

        var simplemde = new SimpleMDE({
            element: $(this).find('#'+$(original).attr('id')+' textarea')[0]
        });

        registermdes[$(original).attr('id')] = simplemde;

//        simplemde.codemirror.on("blur", function(){
//            $(original).find('form button[type=cancel]').click();
//        });
    }
});

