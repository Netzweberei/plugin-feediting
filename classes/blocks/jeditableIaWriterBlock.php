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
    protected $template = '<iframe seamless id="###id###" onload="iframeLoaded(\'###id###\')" src="/%s?editor=iframe" style="width: 100%%; border: 0px solid lime;" scrolling="no"></iframe>';
    protected $dataregex = '/(\/(_.*)\.md)/';
    protected $editingMaskMap = [];
}