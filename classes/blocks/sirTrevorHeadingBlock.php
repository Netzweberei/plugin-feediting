<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class sirTrevorHeadingBlock extends ownlineContentBlock
{
    protected $blockType = 'headingBlock';
    protected $mdregex = '/^#/';
    protected $dataregex = '/(.*)/';
    protected $template = '{"type":"heading","data":{"text":"%s"}},';
    protected $editingMaskMap = ['"' => '\"'];
}