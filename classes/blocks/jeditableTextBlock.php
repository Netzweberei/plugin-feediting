<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class jeditableTextBlock extends multilineContentBlock
{
    protected $blockType = 'textBlock';
    protected $mdregex = '/.*/';
    protected $mdregexStop = '/^(\\/_.*)?$/'; // Stop at blankline or iaWriterBlock
    protected $dataregex = '/.*/';
    protected $template = '<div class="###class###" id="###id###">%s</div>';
}