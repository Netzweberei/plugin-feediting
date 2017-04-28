<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class sirTrevorVideoBlock extends ownlineButWriteRegex0ContentBlock
{
    protected $blockType = 'videoBlock';
    protected $mdregex = '/^\{\{ youtube/';
    protected $dataregex = '/(^{\{ youtube\("(.*)".*$)/';
    protected $template = '{"type":"video","data":{"source":"youtube","remote_id":"%1$s"}},';
    protected $editingMaskMap = [];
}