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
use Herbie\DI;

class FeeditableContent
{

    public $collectAllChanges = false;

    public $reloadPageAfterSave = true;

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
            "template" => '<input type="hidden" name="id" value="###id###"/><textarea name="value">%s</textarea><input type="submit" name="cmd" value="save"/>',
            "mdregex" => '/.*/',
            "dataregex" => '/.*/',
            "insert" => 'array'
        ]
    ];

    protected $tmplstrSeparator = '%s';

    protected $remToCloseCurrentBlockWith = '';

    public function __construct(FeeditingPlugin &$plugin, $format, $segmentid = null, $eob = null)
    {
        $this->plugin = $plugin;
        $this->format = $format;
        $this->pluginConfig = $this->plugin->getConfig();

        if ($segmentid) {
            $this->segmentid = $segmentid;
        }
        if ($eob) {
            $this->eob = $eob;
        }

        $this->init();

    }

    protected function init()
    {

        foreach ($this->contentBlocks as $blockId => $blockDef) {
            list($this->{"open" . ucfirst($blockId)}, $this->{"close" . ucfirst($blockId)}) = explode(
                isset($blockDef['tmplstrSeparator']) ? $blockDef['tmplstrSeparator'] : $this->tmplstrSeparator,
                $blockDef['template']
            );
        }

        $this->contentBlocks = array_merge(
            [
                "exclude-10" => [
                    "mdregex" => '/^$/',
                    "template" => '',
                    "insert" => 'inline'
                ],
                "exclude-20" => [
                    "mdregex" => '/^-- row .*/',
                    "template" => '',
                    "insert" => 'inline'
                ],
                "exclude-20" => [
                    "mdregex" => '/^-- grid .*/',
                    "template" => '',
                    "insert" => 'inline'
                ],
                "exclude-30" => [
                    "mdregex" => '/^----$/',
                    "template" => '',
                    "insert" => 'inline'
                ],
                "exclude-35" => [
                    "mdregex" => '/^--$/',
                    "template" => '',
                    "insert" => 'inline'
                ],
                "exclude-40" => [
                    "mdregex" => '/^-- end --$/',
                    "template" => '',
                    "insert" => 'inline'
                ],
                "exclude-45" => [
                    "mdregex" => '/^-- grid --$/',
                    "template" => '',
                    "insert" => 'inline'
                ],
            ],
            $this->contentBlocks
        );
    }

    public function getSegment($eob = true)
    {
        if ($eob) {
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

            $this->blocks[$id] = $content . $this->eob;

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
        if (trim($content) == '' || trim($content) == PHP_EOL) {
            $content = $this->editableEmptySegmentContent;
        }

        // fresh start or just apppend something?
        if (!$startWithBlockId) {
            $this->blocks = [];
        }

        switch ($this->format) {
            // currently only markdown supported
            case 'markdown':
                $this->{'identify' . ucfirst($this->format) . 'Blocks'}($content, $startWithBlockId, $segmentId);
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
            return $this->pluginConfig['editable_prefix'] . $this->format . '-' . $this->segmentid . '#' . $elemId;
        }
    }

    public function decodeEditableId($elemuri)
    {
        list($contenturi, $elemid) = explode('#', str_replace($this->pluginConfig['editable_prefix'], '', $elemuri));
        list($contenttype, $currsegmentid) = explode('-', $contenturi);

        return array(
            'elemid' => $elemid,
            'segmentid' => $currsegmentid,
            'contenttype' => $contenttype ? $contenttype : $this->format
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
        return '<form method="post" segmentid="' . $segmentId . '">' . $content . '</form>';
    }

    /**
     * @param $content
     * @param int $offset
     * @param bool $segmentId
     * @return int last block-id
     */
    protected function identifyMarkdownBlocks($content, $offset = 0, $segmentId = false)
    {
        $segmentId = $segmentId ? $segmentId : $this->segmentid;
        $eol = PHP_EOL;
        $class = $this->pluginConfig['editable_prefix'] . $this->format . '-' . $segmentId;
        $openBlock = true;
        $blockId = false;

        // prepare for indexing
        $content = $this->stripEmptyContentblocks($content);
        $this->plugin->defineLineFeed($this->getFormat(), '<!--eol-->');

        $lines = explode($eol, $content);
        foreach ($lines as $ctr => $line) {
            $lineno = $this->calcLineIndex($ctr, $offset, $segmentId);

            // switch between save- and edit-view
            $withCmdSave = strpos($this->plugin->cmd, 'save') !== false ? true : false;

            // get rid of MS's-CRs
            $line = strtr(
                $line,
                [
                    "\r" => ''
                ]
            );

            // group special elements in their own block
            foreach ($this->contentBlocks as $b_type => $b_def) {
                // looking for special content which need its own block
                if ($b_type != 'textBlock' && preg_match($b_def['mdregex'], $line, $test)) {
                    // if a "normal" block of multiple lines is still open, we close it
                    if ($blockId !== false) {
                        $this->blocks[$blockId + 1] = ($segmentId === false) ? '' : $this->insertEditableTag(
                            $blockId,
                            $class,
                            'stop',
                            $b_type,
                            MARKDOWN_EOL
                        );
                        $blockId = false;
                        $openBlock = true;
                    }

                    // if edit-view requires masking distinct chars
                    if (isset($b_def['editingMaskMap']) && $withCmdSave === false) {
                        $line = strtr($line, $b_def['editingMaskMap']);
                    }

                    if ($b_def['template'] !== '') {
                        // build special block
                        preg_match($b_def['dataregex'], $line, $b_data);
                        if (count($b_data) > 1) {
                            array_shift($b_data);
                        }
                        switch ($b_def['insert']) {
                            case 'inline':
                                $this->blocks[$lineno] = $this->insertEditableTag(
                                    $lineno,
                                    $class,
                                    'auto',
                                    $b_type,
                                    MARKDOWN_EOL,
                                    $b_data
                                );
                                break;

                            case 'inlineButWriteRegex0':
                                $this->blocks[$lineno] = $this->insertEditableTag(
                                    $lineno,
                                    $class,
                                    'auto',
                                    $b_type,
                                    MARKDOWN_EOL,
                                    $withCmdSave !== false ? reset($b_data) : array_slice($b_data, 1)
                                );
                                break;

                            case 'array':
                                $this->blocks[$lineno - 1] = $this->insertEditableTag(
                                    $lineno,
                                    $class,
                                    'start',
                                    $b_type,
                                    MARKDOWN_EOL
                                );
                                $this->blocks[$lineno] = reset($b_data);
                                $this->blocks[$lineno + 1] = $this->insertEditableTag(
                                    $lineno,
                                    $class,
                                    'stop',
                                    $b_type,
                                    MARKDOWN_EOL
                                );
                                break;
                        }
                    } else {
                        // don't build an editable block
                        $this->blocks[$lineno] = ($ctr == count($lines) - 1) ? $line : $line . $eol;
                    }
                    // continue reading
                    continue 2;
                }
            }
            // edit-view requires to us mask some chars?
            if (isset($b_def['editingMaskMap']) && $withCmdSave === false) {
                $line = strtr($line, $b_def['editingMaskMap']);
            }
            if ($openBlock) {
                $blockId = $lineno;
                $this->blocks[$blockId - 1] = ($segmentId === false) ? '' : $this->insertEditableTag(
                    $blockId,
                    $class,
                    'start',
                    'textBlock',
                    MARKDOWN_EOL
                );
                // respect linebreaks
                $this->blocks[$blockId] = $line . ($b_def['insert'] == 'multiline' ? $this->getLineFeedMarker(
                        $this->getFormat()
                    ) : $eol);
                $openBlock = false;
            } else {
                $this->blocks[$blockId] .= $line . ($b_def['insert'] == 'multiline' ? $this->getLineFeedMarker(
                        $this->getFormat()
                    ) : $eol);
            }
        }

        // if the last block of multiple lines is still open, we close it
        if ($blockId) {
            $this->blocks[$blockId + 1] = ($segmentId === false) ? '' : $this->insertEditableTag(
                $blockId,
                $class,
                'stop',
                $b_type,
                MARKDOWN_EOL
            );
        }

        end($this->blocks);
        return key($this->blocks) + $this->blockDimension;
    }

    private function stripEmptyContentblocks($content)
    {
        $blocks = explode(PHP_EOL, $content);
        $stripped = [];
        $lastBlockUid = 0;
        $beforeLastBlockUid = 0;

        foreach ($blocks as $blockUid => $blockContents) {
            if (
                $lastBlockUid
                && $beforeLastBlockUid
                && $blockContents == ''
                && $stripped[$lastBlockUid] == ''
//                && $stripped[$beforeLastBlockUid] == ''
            ) {
                continue;
            } else {
                $stripped[$blockUid] = $blockContents;
            }
            $beforeLastBlockUid = $lastBlockUid;
            $lastBlockUid = $blockUid;
        }
        return implode(PHP_EOL, $stripped);
    }

    private function calcLineIndex($ctr, $offset, $segmentId = false)
    {
        $segmentId = $segmentId ? $segmentId : $this->segmentid;
        // index + 100 so we have enough "space" to create new blocks "on-the-fly" when editing the page
        $lineno = $ctr * $this->blockDimension + $offset;
        $lineno = $lineno + $segmentId;
        return $lineno;
    }

    private function insertEditableTag(
        $contentUid,
        $contentClass,
        $mode = 'auto',
        $blockType = 'text',
        $eol = PHP_EOL,
        $formatterargs = []
    ) {
        if ($mode == 'stop') {
            return $eol . $this->remToCloseCurrentBlockWith . $eol . PHP_EOL;
        }

        $class = $contentClass;
        $id = $contentClass . '#' . $contentUid;

        $stopBlock = $this->{'close' . ucfirst($blockType)};
        $openBlock = $this->{'open' . ucfirst($blockType)};

        $openBlock = strtr(
            $openBlock,
            array(
                '###id###' => $id,
                '###class###' => $class
            )
        );

        if (!is_array($formatterargs)) {
            $formatterargs = array($formatterargs);
        }

        $openBlock = @vsprintf($openBlock, $formatterargs);
        $stopBlock = @vsprintf($stopBlock, $formatterargs);

        switch ($mode) {

            case 'start':

                $stopmark = '<!-- ###' . $class . '### Stop -->';
                $this->plugin->setReplacement($stopmark, $stopBlock);
                $this->remToCloseCurrentBlockWith = $stopmark;

                $startmark = '<!-- ###' . $id . '### Start -->';
                $this->plugin->setReplacement($startmark, $openBlock);
                return $eol . $startmark . $eol;

            case 'wrap':

                $id = $contentClass;
                $class = $id;

            case 'auto':
            default:

                $startmark = '<!-- ###' . $id . '### Start -->';
                $stopmark = '<!-- ###' . $blockType . '### Stop -->';

                //if( $this->plugin->getReplacement($stopmark) === false)
                $this->plugin->setReplacement($stopmark, $stopBlock);

                //if( $this->plugin->getReplacement($startmark) === false )
                $this->plugin->setReplacement($startmark, $openBlock);

                $ret = $eol . $startmark . $eol . reset($formatterargs) . $eol . $stopmark . $eol . PHP_EOL;
                //$ret = vsprintf($ret, $formatterargs);

                return $ret;
        }
    }

    protected function getLineFeedMarker()
    {
        return PHP_EOL;
    }
} 