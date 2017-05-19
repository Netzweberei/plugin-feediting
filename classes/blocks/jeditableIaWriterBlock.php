<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class jeditableIaWriterBlock extends inlineButWriteRegex0ContentBlock
{
    protected $blockType = 'iaWriterBlock';
    protected $mdregex = '/(?<=^)\\/_.*/s';
    protected $template = '<iframe seamless id="###id###" onload="iframeLoaded(\'###id###\')" src="/%s?editor=iframe" style="width: 100%%; border-width: 0px;" scrolling="no">|</iframe>';
    protected $dataregex = '/(\/(_.*)\.md)/';
    protected $editingMaskMap = [];
    protected $tmplstrSeparator = '|';

    protected function insertBlock($currLineUid, $line)
    {
        // search for the block's data
        preg_match($this->dataregex, $line, $b_data);

        // support default routes to 'index'-files
        $filter = ['/index.md' => '', '/index' => ''];
        $ret    = $this->withCmdSave !== false ? $b_data[1] : $b_data[2];
        $ret    = strtr($ret, $filter);

        $this->editableContent->blocks[$currLineUid] = $this->insertEditableTag(
            $currLineUid,
            $this->class,
            'auto',
            '',
            $ret
        );
    }
}