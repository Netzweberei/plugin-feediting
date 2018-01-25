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

//use Herbie\DI;

class FeeditableContent
{
    public $id;

    public $plugin;

    public $collectAllChanges = false;

    public $reloadPageAfterSave = true;

    public $editableEmptySegmentContent = "\nclick to edit\n";

    public $currBlockId = false;

    public $remToCloseCurrentBlockWith = '';

    public $blocks = [];

    public $pluginConfig = [];

    public $currSegmentId = false;

    public $format = '';

    protected $segmentid = 0;

    protected $segmentLoadedMsg = '';

    protected $blockDimension = 100;

    protected $eob = PHP_EOL;

    protected $contentBlocks = [];

    protected $tmplstrSeparator = '%s';

    public function __construct(FeeditingPlugin &$plugin, $format, $segmentid = null, $eob = false)
    {
        $this->plugin = $plugin;
        $this->format = $format;
        $this->pluginConfig = $this->plugin->getConfig();

        if ($segmentid) {
            $this->segmentid = $segmentid;
        }

        if ($eob !== false) {
            $this->eob = $eob;
        }

        $this->init();
    }

    protected function init()
    {
        $this->registerContentBlocks();
        $this->registerPassthruBlocks();

        foreach ($this->contentBlocks as $contentBlock) {
            if (!$contentBlock->exclude) {
                $this->{"open".ucfirst($contentBlock->blockType)} = $contentBlock->openContainer();
                $this->{"close".ucfirst($contentBlock->blockType)} = $contentBlock->closeContainer();
            }
        }
    }

    protected function registerContentBlocks()
    {
        $this->contentBlocks = [
            new blocks\feeditableTextBlock($this),
        ];
    }

    protected function registerPassthruBlocks()
    {
        $excludeBlocks = [
            new blocks\passthruContentBlock($this, '/^$/'),
            new blocks\passthruContentBlock($this, '/^-- row .*/'),
            new blocks\passthruContentBlock($this, '/^-- grid .*/'),
            new blocks\passthruContentBlock($this, '/^----$/'),
            new blocks\passthruContentBlock($this, '/^--$/'),
            new blocks\passthruContentBlock($this, '/^-- end --$/'),
            new blocks\passthruContentBlock($this, '/^-- grid --$/'),
        ];
        $this->contentBlocks = array_merge($excludeBlocks, $this->contentBlocks);
    }

    public function getSegment($withEob = true)
    {
        if ($withEob) {
            $content = implode($this->getEob(), $this->getContent());
        } else {
            $this->segmentLoadedMsg = '';
            $content = implode($this->getContent());
        }

        return $content;
    }

    public function getEob()
    {
        return $this->eob;
    }

    public function getContent()
    {
        return $this->blocks;
    }

    public function getContentBlockById($id)
    {
        return $this->blocks[$id] ? $this->blocks[$id] : false;
    }

    public function setContentBlockById($id, $content)
    {
        if ($this->blocks[$id]) {

            $this->blocks[$id] = $content.$this->getEob();

            // Reindex all blocks
            $modified = $this->plugin->renderRawContent(implode($this->getContent()), $this->getFormat(), true);
            $this->setContentBlocks($modified);

            return true;
        }

        return false;
    }

    public function getFormat()
    {
        return $this->format;
    }

    /**
     * @param string $content
     * @param int $uid
     * @return array('blocks', 'eop', 'format')
     */
    public function setContentBlocks($content, $startWithBlockId = 0, $segmentId = false)
    {
        // replace empty content
        if (trim($content) == '' || trim($content) == $this->getEob()) {
            $content = $this->editableEmptySegmentContent;
        }

        // fresh start or just apppend something?
        if (!$startWithBlockId) {
            $this->blocks = [];
        }

        switch ($this->format) {
            // currently only markdown supported
            case 'markdown':
                return $this->{'identify'.ucfirst($this->format).'Blocks'}($content, $startWithBlockId, $segmentId);
            case 'raw':
            default:
                // do nothing (yet)
        }
    }

    public function getSegmentLoadedMsg()
    {
        return $this->segmentLoadedMsg;
    }

    public function encodeEditableId($elemId)
    {
        if (!($this->pluginConfig['editable_prefix'] && $this->format && $this->segmentid)) {
            return false;
        } else {
            return $this->pluginConfig['editable_prefix'].$this->format.'-'.$this->segmentid.'#'.$elemId;
        }
    }

    public function decodeEditableId($elemuri)
    {
        list($contenturi, $elemid) = explode('#', str_replace($this->pluginConfig['editable_prefix'], '', $elemuri));
        list($contenttype, $currsegmentid) = explode('-', $contenturi);

        return array(
            'elemid' => $elemid,
            'segmentid' => $currsegmentid,
            'contenttype' => $contenttype ? $contenttype : $this->format,
        );
    }

    public function getEditablesCssConfig($path = null)
    {
    }

    public function getEditablesJsConfig($path = null)
    {
    }

    public function getEditableContainer($segmentId, $content)
    {
        return '<form method="post" segmentid="'.$segmentId.'">'.$content.'</form>';
    }

    /**
     * @param $content
     * @param int $offset
     * @param bool $segmentId
     * @return int last block-id
     */
    protected function identifyMarkdownBlocks($content, $offset = 0, $segmentId = false)
    {
        $this->currBlockId = false;
        $this->currSegmentId = $segmentId ? $segmentId : $this->segmentid;

        $class = $this->pluginConfig['editable_prefix'].$this->format.'-'.$this->currSegmentId;
        $b_def = null;
        $content = $this->stripEmptyContentblocks($content);
        $lines = explode($this->getEob(), $content);
        $ctr = 0;
        $ctlines = count($lines);

        $this->plugin->defineLineFeed($this->getFormat(), '<!--eol-->');

        while ($ctr < $ctlines) {

            $line = $lines[$ctr];

            // sanitize the found contents, i.e. get rid of MS's-CRs:
            $line = strtr($line, ["\r" => '']);

            // do nothing, if line is empty
            if ('' == $line) {
                $ctr++;
                continue;
            }

            // opening a new block requires closing the eventually still opened previous one
            if ($b_def instanceof blocks\abstractContentBlock && $this->currBlockId !== false) {

                $this->blocks[$this->currBlockId + 1] = ($this->currSegmentId === false)
                    ? ''
                    : $b_def->insertEditableTag($this->currBlockId, $class, 'stop', MARKDOWN_EOL);

                $this->currBlockId = false;
            }

            // current line matches a block-definition?
            foreach ($this->contentBlocks as $b_def) {

                $pointer = $b_def->filterLine($lines, $ctr, $offset);
                if ($pointer > $ctr) {

                    $ctr = $pointer;
                    continue 2;
                }
            }
            $ctr++;
        }

        // if the last block of multiple lines is still open, we close it
        if ($this->currBlockId) {

            $this->blocks[$this->currBlockId + 1] = ($this->currSegmentId === false)
                ? ''
                : $b_def->insertEditableTag($this->currBlockId, $class, 'stop', MARKDOWN_EOL);
        }

        end($this->blocks);

        return key($this->blocks) + $this->blockDimension;
    }

    private function stripEmptyContentblocks($content)
    {
        $blocks = explode($this->getEob(), $content);
        $stripped = [];
        $lastBlockUid = 0;
        $beforeLastBlockUid = 0;

        foreach ($blocks as $blockUid => $blockContents) {
            if (
                $lastBlockUid
                && $beforeLastBlockUid
                && $blockContents == ''
                && $stripped[$lastBlockUid] == ''
            ) {
                continue;
            } else {
                $stripped[$blockUid] = $blockContents;
            }
            $beforeLastBlockUid = $lastBlockUid;
            $lastBlockUid = $blockUid;
        }

        return implode($this->getEob(), $stripped);
    }

    public function calcLineIndex($ctr, $offset, $segmentId = false)
    {
        $segmentId = $segmentId ? $segmentId : $this->segmentid;
        // index + 100 so we have enough "space" to create new blocks "on-the-fly" when editing the page
        $currLineUid = $ctr * $this->blockDimension + $offset;
        $currLineUid = $currLineUid + $segmentId;

        return $currLineUid;
    }

    public function getLineFeedMarker()
    {
        switch ($this->getFormat()) {
        }

        return PHP_EOL;
    }

    public function getFidelity()
    {
        switch(@$_REQUEST['editor']){
            case 'iframe':
                return 'lo';
        }
        return 'hi';
    }
} 