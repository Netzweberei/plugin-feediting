<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class jeditableBlocksBlock extends inlineFilterContentBlock
{
    protected $blockType = 'blocksBlock';
    protected $mdregex = '/(?<=^)\\[blocks\\]/s';
    protected $template = '<iframe seamless id="###id###" onload="iframeLoaded(\'###id###\')" src="/%2$s" style="width: 100%%; border: 0px solid lime;" scrolling="no">%s</iframe>';
    protected $dataregex = '/(\[blocks\])/';
    protected $editingMaskMap = [];
}