<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class sirTrevorImageBlock extends ownlineButWriteRegex0ContentBlock
{
    protected $blockType = 'imageBlock';
    protected $mdregex = '/^\!\[/';
    protected $dataregex = '/(^.*\((.*)\).*$)/';
    protected $template = '{"type":"image","data":{"file":{"url":"%s"}}},';
    protected $editingMaskMap = ['/site' => '@site'];
}