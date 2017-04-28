<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class sirTrevorImagineBlock extends ownlineContentBlock
{
    protected $blockType = 'imagineBlock';
    protected $mdregex = '/^\<img/';
    protected $dataregex = '//';
    protected $template = '{"type":"text","data":{"text":"%s--------------------"}},';
    protected $editingMaskMap = ['"' => '\"'];
}