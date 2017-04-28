<?php
/**
 * Created by PhpStorm.
 * User: andreas
 * Date: 17.04.17
 * Time: 17:57
 */

namespace herbie\plugin\feediting\classes\blocks;


use herbie\plugin\feediting\classes\FeeditableContent;

abstract class abstractContentBlock extends \ArrayIterator
{
    protected $editableContent;
    protected $blockType = '';
    protected $template = '';
    protected $mdregex = '';
    protected $dataregex = '';
    protected $tmplstrSeparator = '%s';
    protected $editingMaskMap = [];
    protected $mdregexStop = '//';
    protected $class = '';
    protected $withCmdSave = false;

    public function __construct(FeeditableContent &$editableContent)
    {
        $this->editableContent = $editableContent;
    }

    public function __get($name)
    {
        if (isset($this->$name)) {
            return $this->$name;
        } else {
            return false;
        }
    }

    public function __set($name, $value)
    {
        if (isset($this->$name)) {
            $this->$name = $value;
        }
    }

    public function openContainer()
    {
        return substr($this->template, 0, strpos($this->template, $this->tmplstrSeparator));
    }

    public function closeContainer()
    {
        return substr(
            $this->template,
            strpos($this->template, $this->tmplstrSeparator) + strlen($this->tmplstrSeparator)
        );
    }

    public function insertEditableTag($contentUid, $contentClass, $mode = 'inline', $eol = PHP_EOL, $formatterargs = [])
    {

        $class = $contentClass;
        $id = $contentClass.'#'.$contentUid;
        if (!is_array($formatterargs)) {
            $formatterargs = array($formatterargs);
        }

        $openBlock = @vsprintf(
            strtr($this->openContainer(), ['###id###' => $id, '###class###' => $class]),
            $formatterargs
        );
        $stopBlock = @vsprintf($this->closeContainer(), $formatterargs);

        switch ($mode) {

            case 'start':

                $startmark = '<!-- ###'.$id.'### Start -->';
                $this->editableContent->plugin->setReplacement($startmark, $openBlock);

                $stopmark = '<!-- ###'.$class.'### Stop -->';
                $this->editableContent->plugin->setReplacement($stopmark, $stopBlock);

                $this->editableContent->remToCloseCurrentBlockWith = $stopmark;

                return $eol.$startmark.$eol;

            case 'stop':
                return $eol.$this->editableContent->remToCloseCurrentBlockWith.$eol.PHP_EOL;

            default:

                $startmark = '<!-- ###'.$id.'### Start -->';
                $this->editableContent->plugin->setReplacement($startmark, $openBlock);

                $stopmark = '<!-- ###'.$this->blockType.'### Stop -->';
                $this->editableContent->plugin->setReplacement($stopmark, $stopBlock);

                return $eol.$startmark.$eol.reset($formatterargs).$eol.$stopmark.$eol.PHP_EOL;
        }
    }

    public function filterLine($lines, $ctr, $offset)
    {
        $ret = $ctr;
        $line = $lines[$ctr];
        $currLineUid = $this->editableContent->calcLineIndex($ctr, $offset, $this->editableContent->currSegmentId);

        $this->withCmdSave = strpos($this->editableContent->plugin->cmd, 'save') !== false ? true : false;
        $this->class = $this->editableContent->pluginConfig['editable_prefix'].$this->editableContent->format.'-'.$this->editableContent->currSegmentId;

        // looking for special content which need its own block
        if (preg_match($this->mdregex, $line)) {

            $ret++;

            // in case editing requires some masking...
            if (false === $this->withCmdSave && isset($this->editingMaskMap)) {

                $line = strtr($line, $this->editingMaskMap);
            }

            if ($this->template !== '') {

                $this->insertBlock($currLineUid, $line);
            }
        }

        return $ret;
    }

    protected function insertBlock($currLineUid, $line)
    {
    }
}