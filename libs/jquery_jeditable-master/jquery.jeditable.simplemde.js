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

function iframeLoaded(iframeID) {
    var iframe = document.getElementById(iframeID);
    if(iframe) {
        iframe.height = "";
        $(iframe).css('height', iframe.height);

        iframe.height = iframe.contentWindow.document.body.scrollHeight + "px";
        $(iframe).css('height', iframe.height);

        console.log('set height to ' + iframe.height);
    }
}

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

        /* make sure, only one editor visible at a time */
        for(i in registermdes){
            //console.log(i);
            if(i != $(original).attr('id')){
                //console.log(registermdes[i].element.parentNode.parentNode);
                //console.log($(registermdes[i].element.parentNode.parentNode).find('form button[type=cancel]'));
                $(registermdes[i].element.parentNode.parentNode).find('form button[type=cancel]').click();
            }
        }

        var simplemde = new SimpleMDE({
            autofocus: true,
            element: $(this).find('#'+$(original).attr('id')+' textarea')[0]
        });

        //simplemde.codemirror.on("blur", function(){
        //    console.log('blur...');
        //});

        inlineAttachment.editors.codemirror4.attach(simplemde.codemirror, {
            onFileUploadResponse: function(xhr) {
                var result = JSON.parse(xhr.responseText),
                    filename = result[this.settings.jsonFieldName];

                if (result && filename) {
                    var newValue;
                    if (typeof this.settings.urlText === 'function') {
                        newValue = this.settings.urlText.call(this, filename, result);
                    } else {
                        newValue = this.settings.urlText.replace(this.filenameTag, filename);
                    }
                    var text = this.editor.getValue().replace(this.lastValue, newValue);
                    this.editor.setValue(text);
                    this.settings.onFileUploaded.call(this, filename);
                }
                return false;
            }
        });

        /* Recalculate iframe-height */
        if(window.frameElement && window.frameElement.id && window.parent) {
            window.parent.iframeLoaded(window.frameElement.id);
        }

        $(original).find('form button[type=cancel]').bind('click', function(){
            //console.log('jeditable clicked!');
            /* Recalculate iframe-height */
            if(window.frameElement && window.frameElement.id && window.parent) {
                window.parent.iframeLoaded(window.frameElement.id);
            }
        });

        registermdes[$(original).attr('id')] = simplemde;
    }
});

