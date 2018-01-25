<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class jeditableHeadingBlock extends arrayContentBlock
{
    protected $blockType = 'headingBlock';
    protected $mdregex = '/#+/';
    protected $template = '<div class="###class###" id="###id###" markdown="1">%s</div>';
    protected $dataregex = '/.*/';
    protected $editingMaskMap = [];
}