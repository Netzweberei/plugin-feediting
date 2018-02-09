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

class FeeditableContent
{
    public $id;

    public $plugin;

    public $collectAllChanges = false;

    public $reloadPageAfterSave = true;

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

    /**
     * FeeditableContent constructor.
     * @param FeeditingPlugin $plugin
     * @param $format
     * @param null $segmentid
     * @param bool $eob
     */
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
                $this->{"open" . ucfirst($contentBlock->blockType)} = $contentBlock->openContainer();
                $this->{"close" . ucfirst($contentBlock->blockType)} = $contentBlock->closeContainer();
            }
        }
    }

    protected function registerContentBlocks()
    {
        $this->contentBlocks = [
            new blocks\feeditableTextBlock($this),
        ];
    }

    /**
     * @param array $moreExcludeBlocks
     */
    protected function registerPassthruBlocks($moreExcludeBlocks = [])
    {
        $excludeBlocks = array_merge([
            new blocks\passthruContentBlock($this, '/^$/'),
            new blocks\passthruContentBlock($this, '/^-- row .*/'),
            new blocks\passthruContentBlock($this, '/^-- grid .*/'),
            new blocks\passthruContentBlock($this, '/^----$/'),
            new blocks\passthruContentBlock($this, '/^--$/'),
            new blocks\passthruContentBlock($this, '/^-- end --$/'),
            new blocks\passthruContentBlock($this, '/^-- grid --$/'),
        ], $moreExcludeBlocks);
        $this->contentBlocks = array_merge($excludeBlocks, $this->contentBlocks);
    }

    /**
     * @param bool $withEob
     * @return string
     */
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

    /**
     * @return bool|string
     */
    public function getEob()
    {
        return $this->eob;
    }

    /**
     * @return array
     */
    public function getContent()
    {
        return $this->blocks;
    }

    /**
     * @param $id
     * @return bool|mixed
     */
    public function getContentBlockById($id)
    {
        return $this->blocks[$id] ? $this->blocks[$id] : false;
    }

    /**
     * @param $id
     * @param $content
     * @return bool
     */
    public function setContentBlockById($id, $content)
    {
        if ($this->blocks[$id]) {
            $this->blocks[$id] = $content . $this->getEob();
            // Reindex all blocks
            $modified = $this->plugin->renderRawContent(implode($this->getContent()), $this->getFormat(), true);
            $this->setContentBlocks($modified);
            return true;
        }
        return false;
    }

    /**
     * @return string
     */
    public function getFormat()
    {
        return $this->format;
    }

    /**
     * @param $content
     * @param int $startWithBlockId
     * @param bool $segmentId
     * @return mixed
     */
    public function setContentBlocks($content, $startWithBlockId = 0, $segmentId = false)
    {
        if (!$startWithBlockId) {
            $this->blocks = [];
        }

        switch ($this->format) {
            // currently only markdown supported
            case 'markdown':
                return $this->{'identify' . ucfirst($this->format) . 'Blocks'}($content, $startWithBlockId, $segmentId);
            case 'raw':
            default:
                ;// do nothing (yet)
        }
    }

    /**
     * @return string
     */
    public function getSegmentLoadedMsg()
    {
        return $this->segmentLoadedMsg;
    }

    /**
     * @param $elemId
     * @return bool|string
     */
    public function encodeEditableId($elemId)
    {
        if (!($this->pluginConfig['editable_prefix'] && $this->format && $this->segmentid)) {
            return false;
        } else {
            return $this->pluginConfig['editable_prefix'] . $this->format . '-' . $this->segmentid . '#' . $elemId;
        }
    }

    /**
     * @param $elemuri
     * @return array
     */
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

    /**
     * @param null $path
     */
    public function getEditablesCssConfig($path = null)
    {
    }

    /**
     * @param null $path
     */
    public function getEditablesJsConfig($path = null)
    {
    }

    /**
     * @param $segmentId
     * @param $content
     * @return string
     */
    public function getEditableContainer($segmentId, $content)
    {
        return '<form method="post" segmentid="' . $segmentId . '">' . $content . '</form>';
    }

    /**
     * @param $ctr
     * @param $offset
     * @param bool $segmentId
     * @return int
     */
    public function calcLineIndex($ctr, $offset, $segmentId = false)
    {
        $segmentId = $segmentId
            ? $segmentId
            : $this->segmentid;
        $currLineUid = $ctr * $this->blockDimension + $offset;
        $currLineUid = $currLineUid + $segmentId;

        return $currLineUid;
    }

    public function getLineFeedMarker()
    {
        return PHP_EOL;
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
        $this->plugin->defineLineFeed($this->getFormat(), '<!--eol-->');
        $class  = $this->pluginConfig['editable_prefix'] . $this->format . '-' . $this->currSegmentId;
        $b_def  = null;
        $content= $this->stripEmptyContentblocks($content);
        $lines  = explode($this->getEob(), $content);
        $ctr    = 0;
        $ctlines= count($lines);

        while ($ctr < $ctlines) {
            $line = $lines[$ctr];
//            $line = strtr($line, ["\r" => '']);

            if ('' == $line) {
                $ctr++;
                continue;
            }

            if ($b_def instanceof blocks\abstractContentBlock && $this->currBlockId !== false) {
                $this->blocks[$this->currBlockId + 1] = ($this->currSegmentId === false)
                    ? ''
                    : $b_def->insertEditableTag($this->currBlockId, $class, 'stop', MARKDOWN_EOL);
                $this->currBlockId = false;
            }

            foreach ($this->contentBlocks as $b_def) {
                $pointer = $b_def->filterLine($lines, $ctr, $offset);

                if ($pointer > $ctr) {
                    $ctr = $pointer;
                    continue 2;
                }
            }
            $ctr++;
        }

        if ($this->currBlockId) {
            $this->blocks[$this->currBlockId + 1] = ($this->currSegmentId === false)
                ? ''
                : $b_def->insertEditableTag($this->currBlockId, $class, 'stop', MARKDOWN_EOL);
        }

        end($this->blocks);
        return key($this->blocks) + $this->blockDimension;
    }

    /**
     * @param $content
     * @return string
     */
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
} 