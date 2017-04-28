<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class sirTrevorSmartyimageBlock extends ownlineButWriteRegex0ContentBlock
{
    protected $blockType = 'smartyimageBlock';
    protected $mdregex = '/^\[image\s/';
    protected $dataregex = '/(^\[image\s([^\s]+)\s.*$)/';
    protected $template = '{"type":"image","data":{"file":{"url":"%s"}}},';
    protected $editingMaskMap = [];
}