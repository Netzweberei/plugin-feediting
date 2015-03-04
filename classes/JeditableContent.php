<?php

/**
 * This file is part of Herbie.
 *
 * (c) Thomas Breuss <www.tebe.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace herbie\plugin\feediting\classes;

use herbie\plugin\feediting\FeeditingPlugin;

class JeditableContent extends FeeditableContent {

    protected $contentBlocks = [
        "headingBlock" => [
            "template" => '<div class="###class###" id="###id###">%s</div>',
            "mdregex" => '/#+/',
            "dataregex" => '/.*/',
            "insert" => 'array'
        ],
        "textBlock" => [
            "template" => '<div class="###class###" id="###id###">%s</div>',
            "mdregex" => '/.*/',
            "dataregex" => '/.*/',
            "insert" => 'multiline'
        ]
    ];

    protected $segmentLoadedMsg = 'twigify';

    public function load(){

        extract($this->decodeEditableId($_REQUEST['id']));

        $ret = $this->blocks[$elemid] ? $this->blocks[$elemid] : '';

        die($ret);
    }

    public function save(){
        return 'save';
    }

    public function getEditableContainer($contentId, $content){

        if($this->plugin->status == 'reloading' && !$this->reloadPageAfterSave){
            return
                '<div class="'.$this->pluginConfig['contentSegment_WrapperPrefix'].$contentId.'">
                '.
                $content.
                $this->setJSEditableConfig($contentId).
                '
                </div>';
        }

        $this->plugin->includeBeforeBodyEnds($this->setJSEditableConfig( $this->segmentid ));
        return $content;
    }

    public function getEditablesJsConfig( $path=null )
    {
        $this->plugin->includeBeforeBodyEnds($path.'libs/jquery_jeditable-master/jquery.jeditable.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/jquery/jquery-1.8.2.js');
    }

    protected function setJSEditableConfig( $containerId = 0 )
    {
        $blockSelector     = '.'.$this->pluginConfig['editable_prefix'].$this->format.'-'.$containerId;
        $segmentSelector   = '.'.$this->pluginConfig['contentSegment_WrapperPrefix'].$containerId;

        $ret =
'<script type="text/javascript" charset="utf-8">
function withContainer'.ucfirst($containerId).'() {
    $("'.$blockSelector.'").editable("?cmd=save&renderer=markdown", {
        indicator : "<img src=\'/assets/feediting/libs/jquery_jeditable-master/img/indicator.gif\'>",
        loadurl   : "?cmd=load&segmentid='.$containerId.'&renderer=markdown",
        type      : "textarea",
        submit    : "OK",
        cancel    : "Cancel",
        tooltip   : "Click to edit...",
        ajaxoptions : {
            replace : "with",
            container : "'.$segmentSelector.'"
        }
    });
};

$(document).ready(function(){
    withContainer'.ucfirst($containerId).'();
});
</script>';

        return $ret;
    }
}