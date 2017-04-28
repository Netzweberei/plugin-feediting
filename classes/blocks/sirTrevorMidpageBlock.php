<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class sirTrevorMidpageBlock extends multilineContentBlock
{
    protected $blockType = 'textBlock';
    protected $mdregex = '/^\[block\]/';
    protected $dataregex = '/.*/';
    protected $template = '{"type":"text","data":{"text":"%s"}},';
    protected $editingMaskMap = ['"' => '\"'];
    protected $mdregexStop = '/^\[\/block\]/';
}