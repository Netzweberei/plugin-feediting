<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


class inlineFilterContentBlock extends abstractContentBlock
{
    protected function insertBlock($currLineUid, $line)
    {
        // search for the block's data
        preg_match($this->dataregex, $line, $b_data);
        if (count($b_data) > 1) {
            array_shift($b_data);
        }

        if (isset($this->userfunc)) {
            $test = call_user_func(array($this->editableContent->plugin->page, $this->userfunc));
            $filter = preg_filter($this->datafilter, $this->datareplace, $test);
        } else {
            preg_match($this->datafilter[3], $line, $debug);
            $filter = preg_filter($this->datafilter, $this->datareplace, $line);
        }
        if (isset($this->trim)) {
            $filter = trim($filter, $this->trim);
        }
        $b_data[] = $filter;
        $this->editableContent->blocks[$currLineUid] = $this->insertEditableTag(
            $currLineUid,
            $this->class,
            'auto',
            '',
            $b_data
        );
    }
}