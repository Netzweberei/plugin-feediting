<?php

/**
 * This file is part of Herbie.
 *
 * (c) Thomas Breuss <www.tebe.ch>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace herbie\plugin\feediting;

use Herbie\DI;
use Herbie\Hook;
use Herbie\Page;
use Herbie\Http;

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
    define('MDBLOCK_PADDINGTOP', 1);
    define('MDBLOCK_PADDINGBOTTOM', 1);
    define('MDGRID_DELIMITER', '--');
    define('SEGMENT_DELIMITER', '---');
}

class FeeditingPlugin
{
    protected $id;

    protected $config = [];

    public $page;

    protected $response;

    protected $alias;

    protected $authenticated = false;

    protected $segments = [];

    protected $segmentsuri = '';

    protected $editableSegments = [];

    protected $replace_pairs = [];

    protected $remove_pairs = [];

    protected $contentEditor = null;

    protected $editableContent = [];

    protected $lateEditableContent = [];

    protected $editableEmptySegmentContent = "Click to edit";

    protected $session;

    private $editor = 'Feeditable';

    private $userEditor = '';

    private $editorOptions = ['SirTrevor' => 'build', 'Jeditable' => 'review'];

    private $cmd;

    private $recursivePageLoads = 0;

    private $parseSubsegments = false;

    /* @see: https://regex101.com/r/gCaxqW/1 */
    private $subsegment_match = '/((?P<beforegrid>.*?)(?:(?P<grid>(?!<\<gridend>)(?P<gridstart>^-{2}\s+grid\s+.+?-{2}).(?P<gridcontent>.*?).(?P<gridend>-{2}\s+grid\s+-{2})))|(?P<aftergrid>.{2,}))/msi';

    private $subsegmentid_format = '%s[%s]';

    private $subsegment_placeholder = '+++subsegment-%s+++';

    private $isRealPage = true;

    private $prefixEachContentSegment = false;

    /**
     * FeeditingPlugin constructor.
     */
    public function __construct()
    {
        $this->config = DI::get('Config');
        $this->alias = DI::get('Alias');
        $this->authenticated = $this->isAuthenticated();
    }

    /**
     * @return bool
     */
    protected function isAuthenticated()
    {
        return (bool)@$_SESSION['LOGGED_IN'];
    }

    /**
     * @param $attrib
     * @return mixed|boolean false
     */
    public function __get($attrib)
    {
        switch ($attrib) {
            case 'app':
            case 'segments':
            case 'path':
            case 'alias':
            case 'cmd':
            case 'replace_pairs':
            case 'remove_pairs':
            case 'editableEmptySegmentContent':
                return $this->{$attrib};
                break;
            default:
                return false;
        }
    }

    /**
     * @param $funcname
     * @param $args
     * @return mixed|null
     */
    public function __call($funcname, $args)
    {
        if ($this->authenticated === true) {
            switch (count($args)) {
                case 5:
                    return $this->{$funcname}($args[0], $args[1], $args[2], $args[3], $args[4]);
                case 4:
                    return $this->{$funcname}($args[0], $args[1], $args[2], $args[3]);
                case 3:
                    return $this->{$funcname}($args[0], $args[1], $args[2]);
                case 2:
                    return $this->{$funcname}($args[0], $args[1]);
                case 1:
                    return $this->{$funcname}($args[0]);
                default:
                    return $this->{$funcname}();
            }
        } else {
            return null;
        }
    }

    /**
     * @throws \Exception
     */
    public function install()
    {
        $adminpanelRequested = (bool)strpos(DI::get('Request')->getRequestUri(), '/adminpanel');
        if (!$adminpanelRequested) {
            Hook::attach('pluginsInitialized', [$this, 'onPluginsInitialized']);
            Hook::attach('pageLoaded', [$this, 'onPageLoaded']);
            Hook::attach('outputGenerated', [$this, 'onOutputGenerated'], 10);
        }
    }

    public function onPluginsInitialized()
    {
        if ($this->config->isEmpty('plugins.config.feediting.contentSegment_WrapperPrefix')) {
            $this->config->set('plugins.config.feediting.contentSegment_WrapperPrefix', 'feeditable-');
        }
        if ($this->config->isEmpty('plugins.config.feediting.editable_prefix')) {
            $this->config->set('plugins.config.feediting.editable_prefix', 'editable_');
        }
        if ($this->config->isEmpty('plugins.config.feediting.contentBlockDimension')) {
            $this->config->set('plugins.config.feediting.contentBlockDimension', 100);
        }
        if ($this->config->isEmpty('plugins.config.feediting.dontProvideJquery')) {
            $this->config->set('plugins.config.feediting.dontProvideJquery', false);
        }

        // let the user decide, which editor to use
        $this->setRequestedEditor();
    }

    private function setRequestedEditor()
    {
        $this->userEditor = $this->config->get('plugins.config.feediting.editor');
        if ($this->userEditor == 'UserEditor'
            && isset($_GET['editor'])
            && in_array($_GET['editor'], $this->editorOptions)
        ) {
            $editor = array_flip($this->editorOptions);
            $this->userEditor = $_GET['editor'];
            $_SESSION['NWeditor'] = $editor[$this->userEditor];
        }

        // set editor
        switch (@$_SESSION['NWeditor']) {
            case 'Htmlforms':
            case 'Feeditable':
                $this->editor = 'Feeditable';
                $this->parseSubsegments = false;
                break;
            case 'SirTrevor':
                $this->editor = 'SirTrevor';
                $this->parseSubsegments = true;
                break;
            case 'Jeditable':
            case 'SimpleMDE':
            default:
                $this->editor = 'Jeditable';
                $this->parseSubsegments = false;
        }
        $this->userEditor = $this->editorOptions[$this->editor];
    }

    /**
     * @return mixed
     */
    public function getConfig()
    {
        $config = $this->config->toArray();
        return $config['plugins']['config']['feediting'];
    }

    /**
     * @param $uri
     */
    public function includeAfterBodyStarts($uri)
    {
        $defaultIfNoMatches = array(1 => '<body>');
        preg_match('/(<body[^>]*>)/', $this->response->getContent(), $defaultIfNoMatches);
        $this->includeIntoTag($defaultIfNoMatches[1], $uri);
    }

    /**
     * @param $uri
     */
    public function includeBeforeBodyEnds($uri)
    {
        $this->includeIntoTag('</body>', $uri);
    }

    public function resetRecursivePageLoads()
    {
        $this->recursivePageLoads = 0;
    }

    /**
     * @return string
     */
    public function getSegmentsUri()
    {
        return $this->segmentsuri;
    }

    /**
     * @param Page $page
     */
    protected function onPageLoaded(Page $page)
    {
        if (!$this->isRealPage($page)) return;

        $this->recursivePageLoads++;
        if ($this->isEditable($page) && $this->recursivePageLoads <= 1) {

            $this->page = $page;
            $this->setSegmentsUri();
            $this->setCommand(@$_REQUEST['cmd']);
            DI::get('Twig')->getEnvironment()->setCache(false);
            $pathalias = strpos($page->path, '@') !== 0 ? dirname(DI::get('Page')->getPath()) . DIRECTORY_SEPARATOR . $page->path : $page->path;
            $absfile = DI::get('Alias')->get($pathalias);
            $absdir = dirname($absfile);
            $segmentId = (isset($_REQUEST['segmentid'])) ? $_REQUEST['segmentid'] : $this->segmentsuri . '0';
            $absSegmentId = str_replace($this->segmentsuri, '', $segmentId);
            $_twigify = ($this->loadEditableSegments() == 'twigify') ? true : false;

            // eventually override default-layout with local one
            if (!empty($this->page->layout) && file_exists($absdir . '/.layouts/' . $this->page->layout)) {
                DI::get('Twig')->getEnvironment()->getLoader()->prependPath($absdir . '/.layouts/');
            }

            if (count($this->editableContent)
                && isset($_REQUEST['cmd'])
                && in_array($absSegmentId, array_keys($this->editableContent))
                && is_subclass_of($this->editableContent[$absSegmentId], '\\herbie\plugin\\feediting\\classes\\FeeditableContent')
                && is_callable([$this->editableContent[$absSegmentId], $_REQUEST['cmd']])
            ) {
                $this->cmd = $this->editableContent[$absSegmentId]->{$this->cmd}();
            } elseif (isset($_REQUEST['cmd'])
                && in_array('0[' . $absSegmentId . ']', array_keys($this->lateEditableContent))
                && is_subclass_of($this->lateEditableContent['0[' . $absSegmentId . ']'], '\\herbie\plugin\\feediting\\classes\\FeeditableContent')
                && is_callable([$this->lateEditableContent['0[' . $absSegmentId . ']'], $_REQUEST['cmd']])
            ) {
                $this->lateEditableContent['0[' . $absSegmentId . ']']->{$this->cmd}();
            } elseif (isset($_REQUEST['cmd'])
                && is_callable([$this->editableWorker, $_REQUEST['cmd']])
            ) {
                // last resort ;-)
                $this->editableWorker->{$this->cmd}();
            }
            $this->editPage($_twigify);
        }
    }

    /**
     * @param Page|null $page
     * @return bool
     */
    private function isRealPage(Page $page = null)
    {
        if ($page && stripos($page->getPath(), '@plugin') !== false) {
            $this->isRealPage = false;
        }
        return $this->isRealPage;
    }

    /**
     * @param Page $page
     * @return bool
     */
    private function isEditable(Page $page)
    {
        $path = $page->getPath();
        $alias = substr($path, 0, strpos($path, '/'));
        switch ($alias) {
            case '@page':
            case '@post':
                return true;
        }
        return false;
    }

    /**
     * @param bool $contentSegmentWrapper
     */
    private function setSegmentsUri($contentSegmentWrapper = false)
    {
        if ($contentSegmentWrapper) {
            $this->prefixEachContentSegment = true;
        }
        $urltobuild = $this->page->getPath();
        $urltobuild = str_replace(['@page/', '@post/'], ['', ''], $urltobuild);
        $this->segmentsuri = '/' . preg_replace(['/^\d{1,}\-/','/\/\d{1,}\-/','/index/','/\.md$/'],['','/','',''],$urltobuild);
    }

    /**
     * @param $cmd
     */
    protected function setCommand($cmd)
    {
        switch ($cmd) {
            case 'save':
            case 'saveAndReturn':
            case 'load':
            case 'upload':
            case 'reload':
            case 'bypass':
                $this->cmd = $cmd;
                break;
            default:
                $this->cmd = '';
        }
    }

    /**
     * @return string
     */
    private function loadEditableSegments()
    {
        $this->segments = $this->page->getSegments();

        // set default segment for a blank page
        if (!array_key_exists('0', $this->segments)) {
            $this->segments = array_merge(array('0' => PHP_EOL . $this->editableEmptySegmentContent . PHP_EOL), $this->segments);
            $this->setReplacement($this->editableEmptySegmentContent, '');
        }

        foreach ($this->segments as $segmentId => $segmentContents) {
            if (empty(trim($segmentContents))) {
                $segmentContents = PHP_EOL . $this->editableEmptySegmentContent . PHP_EOL;
                $this->setReplacement($this->editableEmptySegmentContent, '');
            }

            // Get rid of these *!#? CRs once and for all!!!
            $segmentContents = PHP_EOL.strtr($segmentContents, ["\r" => '']).PHP_EOL;

            // segment contains subsegments aka "grid-elements"?
            preg_match_all($this->subsegment_match, $segmentContents, $captured);
            if ($this->parseSubsegments && count($captured['grid']) > 0) {

                // grid(s) found, build a "segmented" segment
                //todo: delegate this to contentblock?
                foreach ($captured['grid'] as $gid => $g) {

                    // make the text preceding the grid editable
                    $beforegrid = trim($captured['beforegrid'][$gid]);
                    if ($beforegrid != '') {
                        $offset = $this->buildEditableSegment($segmentId, $captured['beforegrid'][$gid], 0, true);
                    }

                    // append the gridstart as uneditable text
                    if (is_array($this->segments[$segmentId])) {
                        $this->segments[$segmentId][] = PHP_EOL . $captured['gridstart'][$gid] . PHP_EOL;
                    } else {
                        $this->segments[$segmentId] = array(PHP_EOL . $captured['gridstart'][$gid] . PHP_EOL);
                    }

                    $cols = preg_split('/\\n+' . MDGRID_DELIMITER . '\\n+/', $captured['gridcontent'][$gid]);
                    foreach ($cols as $col => $colcontent) {
                        if ($col > 0) {
                            // append the grid-delimiter as uneditable text
                            $this->segments[$segmentId][] = PHP_EOL . MDGRID_DELIMITER . PHP_EOL;
                        }
                        // append the contents as editable text
                        $colcontent = trim($colcontent);
                        if ($colcontent != '') {
                            $offset = $this->buildEditableSegment($segmentId, $colcontent, $offset, true);
                        }
                    }

                    // append the gridend as uneditable text
                    $this->segments[$segmentId][] = PHP_EOL . $captured['gridend'][$gid] . PHP_EOL . PHP_EOL;

                    // make the text trailing the grid editable
                    $aftergrid = trim($captured['aftergrid'][$gid]);
                    if ($aftergrid != '') {
                        $offset = $this->buildEditableSegment($segmentId, $captured['aftergrid'][$gid], 0, true);
                    }
                }
            } else {
                $segmentContents = trim($segmentContents);
                $this->buildEditableSegment($segmentId, $segmentContents);
            }
        }

        // select any editor for common tasks
        $anyEditables = array_merge($this->editableContent, $this->lateEditableContent);
        $anyEditable = reset($anyEditables);
        while (
            !is_a($anyEditable, 'herbie\\plugin\\feediting\\classes\\FeeditableContent')
            && $anyEditable = next($anyEditables)
        ) {
            ;//nothing found yet...
        }
        $this->editableWorker = $anyEditable;
        return $anyEditable->getSegmentLoadedMsg();
    }

    /**
     * @param $mark
     * @param $replacement
     */
    private function setReplacement($mark, $replacement)
    {
        $this->replace_pairs[$mark] = $replacement;
        $this->remove_pairs[$mark] = '';
    }

    /**
     * @param $segmentId
     * @param string $content
     * @param int $offset
     * @param bool $buildSubsegment
     * @return int
     */
    private function buildEditableSegment($segmentId, $content = '', $offset = 0, $buildSubsegment = false)
    {
        $this->contentEditor = "herbie\\plugin\\feediting\\classes\\{$this->editor}Content";
        if ($buildSubsegment) {

            if (!is_array($this->segments[$segmentId])) {
                $this->segments[$segmentId] = [];
            }
            // push the grid back into the segment, but replace editor-contents AFTER 'renderContent' is triggered
            $subsegmentId = sprintf($this->subsegmentid_format, $segmentId, count($this->segments[$segmentId]));
            $this->lateEditableContent[$subsegmentId] = new $this->contentEditor($this, $this->page->format, $subsegmentId);
            $offset = $this->lateEditableContent[$subsegmentId]->setContentBlocks($content, $offset);

            $this->segments[$segmentId][] = sprintf($this->subsegment_placeholder, $subsegmentId);
            // Register a "pseudo"-segment, needed for initializing the js-editors
            $this->segments[$subsegmentId] = true;
        } else {
            $this->editableContent[$segmentId] = new $this->contentEditor($this, $this->page->format, $segmentId);
            $this->editableContent[$segmentId]->setContentBlocks($content);
            $this->segments[$segmentId] = $this->editableContent[$segmentId]->getSegment();
        }
        return $offset;
    }

    /**
     * @param bool $_twigify
     * @throws \Exception
     */
    private function editPage($_twigify = false)
    {
        switch ($this->cmd) {

            case 'save':
            case 'saveAndReturn':

                if (isset($_POST['id'])
                    && (!isset($this->editableContent[0])
                        || strpos($_POST['id'], $this->editableContent[0]->pluginConfig['editable_prefix']) === 0
                    )
                ) {
                    $changed = $this->collectChanges();

                    if ($changed['segmentid'] !== false) {
                        $fheader = $this->getContentfileHeader();
                        $fh = fopen($this->path, 'w');
                        fputs($fh, $fheader);

                        foreach ($this->segments as $segmentId => $segmentContents) {

                            if ($this->recognizePseudoSegmentById($segmentId)) {
                                continue;
                            }
                            $_modifiedContent[$segmentId] = '';

                            if ($segmentId != '0' && $segmentContents !== true) {
                                // put "\r\n" instead of PHP_EOL because the adminpanel-plugin requires it :-((
                                fputs($fh, "\r\n" . SEGMENT_DELIMITER . " {$segmentId} " . SEGMENT_DELIMITER . "\r\n");
                            }

                            if ($this->parseSubsegments && is_array($segmentContents)) {
                                foreach ($segmentContents as $subsegmentId => $subsegmentContent) {
                                    $subsegmentId = sprintf($this->subsegmentid_format, $segmentId, $subsegmentId);

                                    // search for responsible editors
                                    if (in_array($subsegmentId, array_keys($this->lateEditableContent))) {
                                        $_modifiedContent[$segmentId] .= $this->renderRawContent(
                                            $this->lateEditableContent[$subsegmentId]->getSegment(false),
                                            $this->lateEditableContent[$subsegmentId]->getFormat(),
                                            true
                                        );
                                    } elseif (in_array($subsegmentId, array_keys($this->editableContent))) {
                                        $_modifiedContent[$segmentId] .= $this->renderRawContent(
                                            $this->editableContent[$subsegmentId]->getSegment(false),
                                            $this->editableContent[$subsegmentId]->getFormat(),
                                            true
                                        );
                                    } else {
                                        $_modifiedContent[$segmentId] .= $this->renderRawContent($subsegmentContent);
                                    }
                                }
                            } else {
                                $_modifiedContent[$segmentId] = $this->renderRawContent(
                                    $this->editableContent[$segmentId]->getSegment(false),
                                    $this->editableContent[$segmentId]->getFormat(),
                                    true
                                );
                            }
                            fputs($fh,
                                str_repeat(PHP_EOL, MDBLOCK_PADDINGTOP)
                                . trim($_modifiedContent[$segmentId])
                                . str_repeat(PHP_EOL, MDBLOCK_PADDINGBOTTOM));
                        }
                        fclose($fh);
                    }

                    if ($this->cmd == 'saveAndReturn') {
                        return;
                    } else {
                        $this->setCommand('reload');
                    }

                    $this->page->load($this->page->getPath());
                    $_twigify = ($this->loadEditableSegments() == 'twigify')
                        ? true
                        : false;
                    $editableContent = isset($this->lateEditableContent[$changed['segmentid']])
                        ? 'lateEditableContent'
                        : 'editableContent';

                    if (!$this->{$editableContent}[$changed['segmentid']]->reloadPageAfterSave) {
                        // set segment, recalculate blocks
                        $this->page->setSegments([
                            $changed['segmentid'] => $this->renderEditableContent(
                                $changed['segmentid'],
                                $this->{$editableContent}[$changed['segmentid']]->getSegment(),
                                $changed['contenttype'],
                                $_twigify
                            ),
                        ]);
                        // get recalculated segment
                        $segment = $this->page->getSegment($changed['segmentid']);
                        // trigger other content-filters
                        $content = Hook::trigger(
                            Hook::FILTER,
                            'renderContent',
                            $segment->string,
                            $this->page->getData()
                        );
                        // dont reload but render only this 'partial'
                        die($content);
                    } else {
                        // make all segments editable again for full page-reload
                        foreach ($this->segments as $id => $_segment) {
                            $this->segments[$id] = $this->renderEditableContent($id, $_segment, 'markdown', $_twigify);
                        }
                        $this->page->setSegments($this->segments);
                        break;
                    }
                } else {
                    foreach ($this->segments as $k => $v) {
                        $this->segments[$k] = strtr($v, $this->replace_pairs);
                    }
                }
                break;

            case 'bypass':
                break;

            default:
                foreach ($this->segments as $id => $_segment) {

                    if ($this->parseSubsegments && is_array($_segment)) {

                        $this->segments[$id] = '';
                        foreach ($_segment as $subCtr => $_subsegment) {
                            $_subsegmentid = sprintf($this->subsegmentid_format, $id, $subCtr);

                            if (array_key_exists($_subsegmentid, $this->editableContent)
                                && is_subclass_of($this->editableContent[$_subsegmentid],
                                    'herbie\plugin\feediting\classes\FeeditableContent')
                            ) {
                                $this->segments[$id] .= $this->renderEditableContent(
                                    $_subsegmentid,
                                    $_subsegment,
                                    'markdown',
                                    $_twigify
                                );
                                // Register a "pseudo"-segment for initializing the js-editors
                                $this->segments[$_subsegmentid] = true;
                            } else {
                                $this->segments[$id] .= $this->renderContent(
                                    $_subsegmentid,
                                    $_subsegment,
                                    'markdown',
                                    false
                                );
                            }
                        }
                    } else {
                        $this->segments[$id] = $this->renderEditableContent($id, $_segment, 'markdown', $_twigify);
                    }
                }
        }
        $this->setPageSegments();
    }

    /**
     * @return array
     */
    private function collectChanges()
    {
        $this->replace_pairs = [];
        $anyEditor = $this->parseSubsegments
            ? reset($this->lateEditableContent)
            : reset($this->editableContent);
        $posted = $anyEditor->decodeEditableId($_POST['id']);
        $editableContent = ($this->parseSubsegments && isset($this->lateEditableContent[$posted['segmentid']]))
            ? 'lateEditableContent'
            : 'editableContent';
        $ret = [
            'elemid' => false,
            'segmentid' => false,
            'contenttype' => false,
        ];
        switch (@$_REQUEST['renderer']) {
            case 'markdown';
            default:
                $ret['contenttype'] = 'markdown';
        }

        if (!isset($this->{$editableContent}[$posted['segmentid']])) {
            return $ret;
        }

        if ($this->{$editableContent}[$posted['segmentid']]->collectAllChanges === true) {
            $doEditableContents = $this->parseSubsegments
                ? ['editableContent', 'lateEditableContent']
                : ['editableContent'];

            foreach ($doEditableContents as $editableContent) {
                foreach ($this->{$editableContent} as $segmentId => $segmentContent) {
                    $elemId = $segmentContent->encodeEditableId($segmentId);
                    $subElemId = false;

                    if (preg_match('/(.*)\[(.*)\]/', $elemId, $test)) {
                        list($domId, $elemId, $subElemId) = $test;
                    }

                    if ($subElemId !== false && isset($_POST[$elemId][$subElemId])) {

                        if (!$segmentContent->setContentBlockById($segmentId, (string)$_POST[$elemId][$subElemId])) {
                            return $ret;
                        }
                    } elseif (isset($_POST[$elemId])) {

                        if (!$segmentContent->setContentBlockById($elemId, (string)$_POST[$elemId])) {
                            return $ret;
                        }
                    }
                }
            }
        } else {

            if (!$this->{$editableContent}[$posted['segmentid']]->setContentBlockById($posted['elemid'], (string)$_POST['value'])) {
                return $ret;
            }
        }
        $ret['elemid'] = $posted['elemid'];
        $ret['segmentid'] = $posted['segmentid'];
        $ret['contenttype'] = $posted['contenttype'];
        return $ret;
    }

    /**
     * @return string
     */
    private function getContentfileHeader()
    {
        $this->path = $this->alias->get($this->page->getPath());
        $fh = fopen($this->path, 'r');

        if ($fh) {
            $currline = 0;
            $fheader = '';
            while (($buffer = fgets($fh)) !== false) {
                $fpart = isset($fpart) ? $fpart : 'header';
                ${'f' . $fpart} .= $buffer;
                $currline++;
                if ($currline > 1 && strpos($buffer, '---') !== false) {
                    break;
                }
            }
        }
        fclose($fh);
        return $fheader;
    }

    /**
     * @param $segmentId
     * @return bool
     */
    private function recognizePseudoSegmentById($segmentId)
    {
        parse_str($segmentId, $arr);
        return (is_array(reset($arr)))
            ? true
            : false;
    }

    /**
     * @param $content
     * @param string $format
     * @param bool $stripLF
     * @return string
     */
    private function renderRawContent($content, $format = 'markdown', $stripLF = false)
    {
        $ret = strtr($content, [
            strtoupper($format) . '_EOL' => PHP_EOL,
            constant(strtoupper($format) . '_EOL') => $stripLF
                ? ''
                : PHP_EOL
        ]);
        return strtr($ret, $this->remove_pairs);
    }

    /**
     * @param $contentId
     * @param $content
     * @param $format
     * @param bool $twigify
     * @return string
     */
    private function renderEditableContent($contentId, $content, $format, $twigify = false)
    {
        $ret = '';
        $content = $this->renderContent($contentId, $content, $format, $twigify);

        if ($this->parseSubsegments && is_array($content)) {
            foreach ($content as $subcontentId => $subcontent) {
                $subsegmentId = sprintf($this->subsegmentid_format, $contentId, $subcontentId);

                if (isset($this->editableContent[$subsegmentId])) {

                    $ret .= $this->editableContent[$subsegmentId]->getEditableContainer(
                        $subsegmentId,
                        strtr($subcontent, $this->replace_pairs)
                    );
                    // Register a "pseudo"-segment for initializing the js-editors
                    $this->segments[$subsegmentId] = true;
                } else {
                    $ret .= strtr($subcontent, $this->replace_pairs);
                }
            }
        } else {

            if (isset($this->editableContent[$contentId])) {

                $ret = $this->editableContent[$contentId]->getEditableContainer(
                    $contentId,
                    strtr($content, $this->replace_pairs)
                );
            }
        }
        return $ret;
    }

    /**
     * @param $contentId
     * @param $content
     * @param $format
     * @param bool $twigify
     * @return string
     */
    private function renderContent($contentId, $content, $format, $twigify = false)
    {
        if ($twigify && !empty($content)) {
            $content = strtr($content, [constant(strtoupper($format) . '_EOL') => PHP_EOL]);
        }
        return $content;
    }

    protected function setPageSegments()
    {
        $segments = [];
        $segment_register = [];
        foreach ($this->segments as $segmentId => $editableContainer) {

            $segments[$segmentId] = $this->prefixEachContentSegment
                ? '<div class="' . $this->editableContent[0]->pluginConfig['contentSegment_WrapperPrefix'] . $segmentId . '">' .
                PHP_EOL .
                $editableContainer .
                PHP_EOL .
                '</div>'
                : $editableContainer;

            // register compound segments (i.e. with subsegments)
            parse_str($segmentId, $register);
            $segment_register = $register + $segment_register;
        }

        if ($this->parseSubsegments) {
            foreach ($segment_register as $segmentId => $v) {

                if (!is_array($segment_register[$segmentId])) {
                    $editableContainer = $segments[$segmentId];
                    $containerPlaceholder = sprintf($this->subsegment_placeholder, $segmentId);
                    $segments[$segmentId] = $containerPlaceholder;
                    $this->replace_pairs[$containerPlaceholder] = $editableContainer;
                }
            }
        }

        foreach ($segments as $i => $s) {
            $segments[strtr($i, [$this->segmentsuri => ''])] = $s;
        }

        $this->page->setSegments($segments);
        $this->page->nocache = true;
    }

    /**
     * @param Http\Response $response
     */
    protected function onOutputGenerated(Http\Response $response)
    {
        if ($response->getStatus() == 404 || !$this->isRealPage()) return;

        $this->response = $response;
        $this->self = $this->config->get('plugins.path') . '/feediting/';
        $this->includeIntoHeader($this->self . 'assets/css/feediting.css');
        $this->getEditablesCssConfig($this->self);
        $this->getEditablesJsConfig($this->self);
        if ('UserEditor' == $this->config->get('plugins.config.feediting.editor')) {
            $options = '';
            foreach ($this->editorOptions as $editor => $option) {
                $options .=
                    '<a href="?editor=' . $option . '" ' . ($this->userEditor == $option ? 'style="text-decoration: underline;"' : '') . '>'
                    . ucfirst($option)
                    . '</a>';
            }
            $this->includeIntoAdminpanel(
                '<div class="feeditingpanel"><a name="FeditableContent"></a>' . $options . '</div>'
            );
        }

        $content = strtr($response->getContent(), $this->replace_pairs);
        if ($this->parseSubsegments) {
            // replace late-editable-contents the least
            $late_replace_pairs = [];
            foreach ($this->lateEditableContent as $editorId => $editor) {
                $late_replace_pairs[sprintf($this->subsegment_placeholder, $editorId)] = $editor->getEditableContainer(
                    $editorId,
                    strtr($editor->getSegment(), $this->replace_pairs)
                );
            }
            $content = strtr($content, $late_replace_pairs);
        }
        $response->setContent($content);
    }

    /**
     * @param $uri
     */
    public function includeIntoHeader($uri)
    {
        $this->includeIntoTag('</head>', $uri);
    }

    /**
     * @param null $tag
     * @param $uri
     */
    private function includeIntoTag($tag = null, $uri)
    {
        if (empty($tag)) return;
        if (!isset($this->replace_pairs[$tag])) $this->replace_pairs[$tag] = $tag;
        if (substr($uri, 0, 1) == '<') {
            // include as tag:
            if (substr($uri, 0, 2) == '</') {
                $this->replace_pairs[$tag] = $uri . PHP_EOL . $this->replace_pairs[$tag];
            } else {
                $this->replace_pairs[$tag] = $this->replace_pairs[$tag] . PHP_EOL . $uri . PHP_EOL;
            }
            return;
        } else {
            $assetAtoms = $this->provideAsset($uri);
            switch (end($assetAtoms)) {
                case '.css':
                    $tmpl = '<link rel="stylesheet" href="%s" type="text/css" media="screen" title="no title" charset="utf-8">';
                    break;
                case '.js':
                    $tmpl = '<script src="%s" type="text/javascript" charset="utf-8"></script>';
                    break;
                default:
                    return;
            }
        }
        $this->replace_pairs[$tag] = sprintf($tmpl, implode($assetAtoms)) . PHP_EOL . $this->replace_pairs[$tag];
    }

    /**
     * @param $uri
     * @return array
     */
    public function provideAsset($uri)
    {
        $pathinfo = pathinfo($uri);
        $webdir = strtr(dirname($uri), [$this->alias->get('@plugin') => '']);
        if (strpos($webdir, '://') > 1) {
            // include external uri:
            $pathPrefix = '';
        } else {
            // copy src to assets
            $pathPrefix = DS . 'assets';
            $webpath = $pathPrefix . $webdir . DS . $pathinfo['basename'];
            $abspath = $this->alias->get('@web') . $webpath;
            if (!file_exists($abspath)) {
                @mkdir(dirname($abspath), 0777, true);
                copy($uri, $abspath);
            }
        }
        return [$pathPrefix, $webdir . DS, $pathinfo['filename'], '.' . $pathinfo['extension']];
    }

    /**
     * @param $pluginPath
     */
    private function getEditablesCssConfig($pluginPath)
    {
        if (!($test = reset($this->editableContent))) {
            $test = reset($this->lateEditableContent);
        }
        $test->getEditablesCssConfig($pluginPath);
    }

    /**
     * @param $pluginPath
     */
    private function getEditablesJsConfig($pluginPath)
    {
        if (!($test = reset($this->editableContent))) {
            $test = reset($this->lateEditableContent);
        }
        $test->getEditablesJsConfig($pluginPath);
    }

    /**
     * @param $html
     */
    public function includeIntoAdminpanel($html)
    {
        $adminpanelTag = '<div class="adminpanel">';
        $this->replace_pairs[$adminpanelTag] = $adminpanelTag . $html;
    }

    private function initSegments()
    {
        $this->setSegmentsUri();
        $segments = $this->page->getSegments();
        foreach ($segments as $id => $s) {
            $this->segments[$this->segmentsuri . $id] = $s;
        }

        if (!array_key_exists($this->segmentsuri . '0', $this->segments)) {
            $this->segments = array_merge(array($this->segmentsuri . '0' => PHP_EOL), $this->segments);
        }
    }

    /**
     * @param $mark
     * @return bool|mixed
     */
    private function getReplacement($mark)
    {
        if (isset($this->replace_pairs[$mark])) {
            return $this->replace_pairs[$mark];
        } else {
            return false;
        }
    }

    /**
     * @param $format
     * @param $eol
     */
    private function defineLineFeed($format, $eol)
    {
        $FORMAT_EOL = $this->getLineFeedMarker($format);
        $EDITABLE_FORMAT_EOL = $this->getLineFeedMarker($format, 1);
        // used for saving
        if (!defined($FORMAT_EOL)) {
            define($FORMAT_EOL, $eol);
        }
        // used in in-page-editor
        if (!defined($EDITABLE_FORMAT_EOL)) {
            define($EDITABLE_FORMAT_EOL, $eol);
        }
        $this->replace_pairs[$eol] = '';
        $this->remove_pairs[$eol] = '';
    }

    /**
     * @param $format
     * @param bool $editable
     * @return string
     */
    public function getLineFeedMarker($format, $editable = false)
    {
        return ($editable
                ? 'EDITABLE_'
                : ''
            ) . strtoupper($format) . '_EOL';
    }
}

(new FeeditingPlugin)->install();