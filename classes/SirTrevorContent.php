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

class SirTrevorContent extends FeeditableContent {

    public $collectAllChanges = true;

    public $reloadPageAfterSave = true;

    public $editableEmptySegmentContent = PHP_EOL;

    protected $contentBlocks = [
        "headingBlock" => [
            "template" => '{"type":"heading","data":{"text":"%s"}},',
            "mdregex" => '/^#/',
            "dataregex" => '/(.*)/',
            "insert" => 'inline'
        ],
        "widgetBlock" => [
            "template" => '{"type":"widget","data":{"selected":"%s"}},',
            "mdregex" => '/^\{\{\s?widget/',
            "dataregex" => '/\([\'\"]{1}(.*)[\'\"]{1}\)/',
            "insert" => 'inline'
        ],
        "imageBlock" => [
            "template" => '{"type":"image","data":{"file":{"url":"%s"}}},',
            "mdregex" => '/^\!\[/',
            "dataregex" => '/\((.*)\)/',
            "insert" => 'inline'
        ],
        // @TODO: Define custom-imagine-block for ST
        "imagineBlock" => [
            "template" => '{"type":"image","data":{"file":{"url":"media/%s"}}},',
            "mdregex" => '/^\<img/',
            "dataregex" => '/\'([a-zA-Z\.0-9\-\_]*)\'/',
            "insert" => 'inline'
        ],
        "textBlock" => [
            "template" => '{"type":"text","data":{"text":"%s"}},',
            "mdregex" => '/.*/',
            "dataregex" => '/.*/',
            "insert" => 'inline'
        ],
    ];

    protected $contentContainer = '{"data":[%s{}]}';

    protected $segmentLoadedMsg = '';

    public function save(){
        return 'save';
    }

    public function getEditablesCssConfig($path=null){
        $this->plugin->includeIntoHeader($path.'libs/jquery_fancybox/jquery.fancybox.css');
        $this->plugin->includeIntoHeader($path.'libs/sir-trevor-js/sir-trevor-icons.css');
        $this->plugin->includeIntoHeader($path.'libs/sir-trevor-js/sir-trevor.css');
    }

    public function getEditablesJsConfig( $path=null )
    {
        $this->plugin->includeAfterBodyStarts('<form method="post">');
        $this->plugin->includeAfterBodyStarts('<input type="hidden" name="cmd" value="save">');
        $this->plugin->includeBeforeBodyEnds('</form>');

        foreach($this->plugin->segments as $segmentid => $segment)
        {
            $this->plugin->includeBeforeBodyEnds(
'<script type="text/javascript" charset="utf-8">'.
'
      window.editor'.$segmentid.' = new SirTrevor.Editor({
        el: $(".sirtrevor-'.$segmentid.'"),
        blockTypes: [
          "Text",
          "Heading",
//          "List",
//          "Quote",
          "Image",
          "Video",
//          "Tweet",
          "Widget"
        ],
        defaultType: "Text"
      });
      SirTrevor.setDefaults({
        uploadUrl: "/?cmd=upload"
      });
'.
'</script>'
            );
        }
        $this->plugin->includeBeforeBodyEnds($path.'libs/jquery_fancybox/jquery.fancybox.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/sir-trevor-js/locales/de.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/sir-trevor-js/sir-trevor.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/Eventable/eventable.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/underscore/underscore.js');
        $this->plugin->includeBeforeBodyEnds($path.'libs/jquery/jquery-1.8.2.js');
    }

    public function getEditableContainer($contentId, $content)
    {
        $ret = '';

        if($contentId == 0) {
            $ret .= '<div class="st-submit"><input type="submit" value="click to save changes" class="top" ><input type="hidden" name="id" value="sirtrevor-'.$contentId.'" ></input></div>';
        }
        if($this->plugin->cmd == 'editWidget'){
            $ret .= '<input type="hidden" name="cmd" value="saveWidget" />';
            $ret .= '<input type="hidden" name="name" value="'.$_REQUEST['name'].'" />';
        }
        $ret .= '<textarea name="sirtrevor-'.$contentId.'" class="sirtrevor-'.$contentId.'">'.sprintf($this->contentContainer, $content).'</textarea>';

        return $ret;
    }

    public function encodeEditableId($elemId)
    {
        return 'sirtrevor-'.$elemId;
    }

    public function decodeEditableId($elemId)
    {
        list($contenttype, $currsegmentid) = explode('-', $elemId);

        return array(
            'elemid'        => $elemId,
            'segmentid'     => $currsegmentid,
            'contenttype'   => 'sirtrevor'
        );
    }

    public function getSegment($json_encode=true){
        if($json_encode){
            return implode( $this->getEob(),  $this->getContent() );
        }
        else
            return implode( $this->getContent($json_decode=true) );
    }

    public function getContent($json_decode=false){
        $ret = [];
        if($json_decode){
            $ret = $this->json2array($this->getContentBlockById($this->segmentid));
        } else {
            foreach($this->blocks as $k => $v){
                $ret[$k] = strtr($v, array(
                    '"' => '\"'
                ));
            }
        }
        return $ret;
    }

    public function getContentBlockById($id){
        $ret = null;
        if($this->blocks){
            $html       = implode($this->blocks);
            $oneline    = strtr($html, array(
                PHP_EOL => '',
                '\\n'   => '',
                '\\'    => '',
            ));
            $json       = strtr($oneline, array_merge(array('"'=>'\"'), $this->plugin->replace_pairs));
            $ret        = sprintf($this->contentContainer, $json);
        }
        return $ret;
    }

    public function setContentBlockById($id, $json){

        if($this->blocks)
        {
            // replace current segment
            $this->blocks = $this->json2array($json);

            // Reindex all blocks
            $modified = $this->plugin->renderRawContent(implode($this->getContent()), $this->getFormat(), true );
            $this->setContent($modified);

            return true;
        }
        return false;
    }

    public function getWidgetByName(){
        $widgetsExtension = new \herbie\plugin\widgets\classes\WidgetsExtension($this->plugin->app);
        $widgetsExtension->renderWidget($_REQUEST['name'], $fireEvents=true);
        return $_cmd = 'bypass';
    }

    public function saveWidget(){
        $widgetsExtension = new \herbie\plugin\widgets\classes\WidgetsExtension($this->plugin->app);
        $widgetsExtension->renderWidget($_REQUEST['name'], $fireEvents=true);
        return $_cmd = 'bypass';
    }

    private function json2array($json){

        $blocks = array();
        $content = json_decode($json);
        if(isset($content->data))
        {
            foreach($content->data as $_block)
            {
                $blocks[] = PHP_EOL;
                if(isset($_block->type))
                {
                    switch($_block->type)
                    {
                        case 'widget':
                            $blocks[] = '{{widget(\''.basename($_block->data->selected).'\')}}'.PHP_EOL;
                            break;
                        case 'image':
                            $blocks[] = '!['.basename($_block->data->file->url).']('.$_block->data->file->url.')'.PHP_EOL;
                            break;
                        case 'heading':
                            $test = strtr(trim($_block->data->text), array(PHP_EOL => '')).PHP_EOL;
                            if(substr($test, 0, 1)!=='#'){
                                $test = '#'.$test;
                            }
                            $blocks[] = $test;
                            break;
                        case 'text':
                        default:
                            $blocks[] = strtr($_block->data->text, array(PHP_EOL => '')).PHP_EOL;
                            break;
                    }
                }

            }
        }
        return $blocks;
    }

    public function upload(){

        if($_FILES)
        {
            $uploaddir = dirname($this->plugin->path);
            $uploadfile = $uploaddir . DS. basename($_FILES['attachment']['name']['file']);
            if (move_uploaded_file($_FILES['attachment']['tmp_name']['file'], $uploadfile))
            {
                $relpath = strtr($uploadfile, array($this->plugin->alias->get('@site') => '/site'));
                $sirtrevor = '{ "file": { "url" : "'.$relpath.'" } }';
                die($sirtrevor);
            }
        }
    }

    public function loadAvailableWidgets(){
        die('{"type": "widget", "data": {"available": [
            {
                "name": "box1",
                "icon": "box",
                "type": "box",
                "uri": "_box1"
            },
            {
                "name": "box2",
                "icon": "widget",
                "type": "box",
                "uri": "_box2"
            }
        ]}}');
    }

} 