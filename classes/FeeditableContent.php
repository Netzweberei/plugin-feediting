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

class FeeditableContent {

    public $collectAllChanges = false;

    public $reloadPageAfterSave = false;

    public $editableEmptySegmentContent = "\nclick to edit\n";

    protected $blocks = [];

    protected $format = '';

    protected $plugin;

    protected $segmentid = 0;

    protected $segmentLoadedMsg = '';

    protected $blockDimension = 100;

    protected $pluginConfig = [];

    protected $eob = PHP_EOL;

    protected $contentBlocks = [
        "textBlock" => [
            "template" => '<textarea name="###id###">%s</textarea><input type="submit" name="cmd" value="save"/>',
            "mdregex" => '/.*/',
            "dataregex" => '/.*/',
            "insert" => 'array'
        ]
    ];

    protected $remToCloseCurrentBlockWith = '';
    
    public function __construct(FeeditingPlugin &$plugin, $format, $segmentid = null, $eob = null)
    {
        $this->plugin = $plugin;
        $this->format = $format;
        $this->pluginConfig = $this->plugin->getConfig();

        if($segmentid) $this->segmentid = $segmentid;
        if($eob) $this->eob = $eob;

        $this->init();

    }

    protected function init(){

        foreach($this->contentBlocks as $blockId => $blockDef){
            list($this->{"open".ucfirst($blockId)},$this->{"close".ucfirst($blockId)}) = explode('%s', $blockDef['template']);
        }

        $this->contentBlocks = array_merge([
            "exclude-1" => [
                "mdregex" => '/^-- row .*/',
                "template" => '',
                "insert" => 'inline'
            ],
            "exclude-2" => [
                "mdregex" => '/^-- end --$/',
                "template" => '',
                "insert" => 'inline'
            ],
            "exclude-3" => [
                "mdregex" => '/^----$/',
                "template" => '',
                "insert" => 'inline'
            ],
            "exclude-4" => [
                "mdregex" => '/^$/',
                "template" => '',
                "insert" => 'inline'
            ],
        ], $this->contentBlocks);
    }

    public function getFormat(){
        return $this->format;
    }

    public function getEob(){
        return $this->eob;
    }

    public function getSegment($eob=true){
        if($eob){
            return implode( $this->getEob(),  $this->getContent() );
        }
        else {
            $this->segmentLoadedMsg = '';
            return implode( $this->getContent() );
        }
    }

    /**
     * @param string $content
     * @param int $uid
     * @return array('blocks', 'eop', 'format')
     */
    public function setContent($content)
    {
        // currently only (twitter-bootstrap)markdown supported!
        $this->{'identify'.ucfirst($this->format).'Blocks'}($content);

        // strip empty blocks
        $this->stripEmptyContentblocks();
    }

    public function getContent(){
        return $this->blocks;
    }

    public function getContentBlockById($id){
        return $this->blocks[$id] ? $this->blocks[$id] : false;
    }

    public function setContentBlockById($id, $content){
        if($this->blocks[$id]) {
            // TODO: jason_decode!
            $this->blocks[$id] = $content.$this->eob;

            // Reindex all blocks
            $modified = $this->plugin->renderRawContent(implode($this->getContent()), $this->getFormat(), true );
            $this->setContent($modified);

            return true;
        }
        return false;
    }

    public function getSegmentLoadedMsg(){
        return $this->segmentLoadedMsg;
    }

    public function encodeEditableId($elemId)
    {
        if(!($this->pluginConfig['editable_prefix'] && $this->format && $this->segmentid))
            return false;
        else
            return $this->pluginConfig['editable_prefix'].$this->format.'-'.$this->segmentid.'#'.$elemId;
    }

    public function decodeEditableId($elemuri)
    {
        list($contenturi, $elemid)      = explode('#', str_replace($this->pluginConfig['editable_prefix'], '', $elemuri));
        list($contenttype, $currsegmentid) = explode('-', $contenturi);

        return array(
            'elemid' => $elemid,
            'segmentid' => $currsegmentid,
            'contenttype' => $contenttype ? $contenttype : $this->format
        );
    }

    public function getEditablesCssConfig($path=null){}

    public function getEditablesJsConfig( $path=null ){}

    public function getEditableContainer($contentId, $content){
        return '<form method="post" name="$contentId">'.$content.'</form>';
    }

    protected function identifyMarkdownBlocks( $content, $dimensionOffset = 0 )
    {
        $ret        = [];
        $eol        = PHP_EOL;
        $class      = $this->pluginConfig['editable_prefix'].$this->format.'-'.$this->segmentid;
        $openBlock  = true;
        $blockId    = 0;

        $this->plugin->defineLineFeed($this->getFormat(), '<!--eol-->');

        $lines = explode($eol, $content);
        foreach($lines as $ctr => $line)
        {
            $lineno = $this->calcLineIndex($ctr, $dimensionOffset);

            switch($line)
            {
                default:

                    // group special elements in their own block
                    foreach($this->contentBlocks as $b_type => $b_def )
                    {
                        // look for special content which need its own block
                        if( $b_type != 'textBlock' && preg_match($b_def['mdregex'], $line, $test))
                        {
                            // if a "normal" block of multiple lines is still open, we close it
                            if($blockId)
                            {
                                $ret[$blockId+1] = ($this->segmentid === false) ? '' : $this->insertEditableTag($blockId, $class, 'stop', $b_type, MARKDOWN_EOL);
                                $blockId = 0;
                                $openBlock = true;
                            }

                            if($b_def['template'] !== '')
                            {
                                // build special block
                                preg_match($b_def['dataregex'], $line, $b_data);
                                switch($b_def['insert'])
                                {
                                    case 'inline':
                                        $ret[$lineno] = sprintf($this->insertEditableTag($lineno, $class, 'auto', $b_type, MARKDOWN_EOL), end($b_data));
                                        break;

                                    case 'array':
                                        $ret[$lineno-1] = $this->insertEditableTag($lineno, $class, 'start', $b_type, MARKDOWN_EOL);
                                        $ret[$lineno] = end($b_data);
                                        $ret[$lineno+1] = $this->insertEditableTag($lineno, $class, 'stop', $b_type, MARKDOWN_EOL);
                                        break;
                                }
                            } else {
                                // don't build an editable block, eg. bootstrap-markdown's
                                $ret[$lineno] = ( $ctr == count($lines)-1 ) ? $line : $line.$eol;
                            }
                            // continue reading
                            continue 2;
                        }
                    }

                    if($openBlock)
                    {
                        $blockId = $lineno;
                        $ret[$blockId-1] = ($this->segmentid === false) ? '' : $this->insertEditableTag($blockId, $class, 'start', 'textBlock', MARKDOWN_EOL);
                        $ret[$blockId] = $line.$eol;
                        $openBlock = false;
                    }
                    else
                    {
                        $ret[$blockId] .= $line.$eol;
                    }
            }
        }

        // if the last block of multiple lines is still open, we close it
        if($blockId) {
            $ret[$blockId+1] = ($this->segmentid === false) ? '' : $this->insertEditableTag($blockId, $class, 'stop', $b_type, MARKDOWN_EOL);
        }

        $this->blocks = $ret;
    }

    private function calcLineIndex($ctr, $dimensionOffset){
        // index + 100 so we have enough "space" to create new blocks "on-the-fly" when editing the page
        $lineno = $ctr * $this->blockDimension + $dimensionOffset;
        $lineno = $lineno + $this->segmentid;
        return $lineno;
    }

    private function stripEmptyContentblocks()
    {
        if(!is_array($this->blocks) || count($this->blocks)==0) return;

        $stripped = [];
        $lastBlockUid = 0;
        $beforeLastBlockUid = 0;
        
        foreach($this->blocks as $blockUid => $blockContents)
        {
            if(
                $lastBlockUid
                && $beforeLastBlockUid
                && $blockContents == PHP_EOL
                && $stripped[$lastBlockUid] == PHP_EOL
//                && $stripped[$beforeLastBlockUid] == PHP_EOL
            )
                continue;
            else
                $stripped[$blockUid] = $blockContents;

            $beforeLastBlockUid = $lastBlockUid;
            $lastBlockUid       = $blockUid;
        }
        $this->blocks = $stripped;
    }

    private function insertEditableTag( $contentUid, $contentClass, $mode='auto', $blockType='text', $eol = PHP_EOL)
    {
        if($mode == 'stop'){
            return $eol.$this->remToCloseCurrentBlockWith.$eol.PHP_EOL;
        }

        $class = $contentClass;
        $id    = $contentClass.'#'.$contentUid;

        $stopBlock = $this->{'close'.ucfirst($blockType)};
        $openBlock = $this->{'open'.ucfirst($blockType)};
        $openBlock = strtr($openBlock, array(
            '###id###' => $id,
            '###class###' => $class
        ));

        switch($mode){

            case 'start':

                $stopmark = '<!-- ###'.$class.'### Stop -->';
                $this->plugin->setReplacement($stopmark,$stopBlock);
                $this->remToCloseCurrentBlockWith = $stopmark;

                $startmark = '<!-- ###'.$id.'### Start -->';
                $this->plugin->setReplacement($startmark,$openBlock);
                return $eol.$startmark.$eol;

            case 'wrap':

                $id     = $contentClass;
                $class  = $id;

            case 'auto':
            default:

                $startmark = '<!-- ###'.$id.'### Start -->';
                $stopmark  = '<!-- ###'.$blockType.'### Stop -->';

                //if( $this->plugin->getReplacement($stopmark) === false)
                    $this->plugin->setReplacement($stopmark,$stopBlock);

                //if( $this->plugin->getReplacement($startmark) === false )
                    $this->plugin->setReplacement($startmark,$openBlock);

                return $eol.$startmark.$eol.'%s'.$eol.$stopmark.$eol.PHP_EOL;
        }
    }
} 