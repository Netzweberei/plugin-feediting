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

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

class FeeditingPlugin
{
    protected $config = [];

    protected $response;

    protected $alias;

    protected $authenticated = false;

    protected $editableSegments = [];

    protected $replace_pairs = [];

    protected $remove_pairs = [];

    protected $editableContent = [];

    protected $lateEditableContent = [];

    protected $session;

    private $editor = 'Feeditable';

    private $editorOptions = ['SirTrevor' => 'Build', 'SimpleMDE' => 'Review'];

    private $cmd;

    private $recursivePageLoads = 0;

    private $parseSubsegments = false;

    private $subsegment_match = '/(?P<beforegrid>.*?)(?:(?P<grid>(?!<\<gridend>)(?P<gridstart>^-{2}\s+grid\s+.+?-{2}).(?P<gridcontent>.*?).(?P<gridend>-{2}\s+grid\s+-{2})))/msi';

    private $subsegmentid_format = '%s[%s]';

    private $subsegment_placeholder = '+++subsegment-%s+++';

    private $isRealPage = true;

    public function __construct()
    {
        $this->config = DI::get('Config');
        $this->alias  = DI::get('Alias');
        $this->authenticated = $this->isAuthenticated();
    }

    protected function isAuthenticated()
    {
        return (bool)@$_SESSION['LOGGED_IN'];
    }

    public function install()
    {
        $adminpanelRequested = strpos(DI::get('Request')->getRequestUri(), '/adminpanel') === 0 ? true : false;
        if (!$adminpanelRequested) {
            Hook::attach('pluginsInitialized', [$this, 'onPluginsInitialized']);
            Hook::attach('pageLoaded', [$this, 'onPageLoaded']);
            Hook::attach('outputGenerated', [$this, 'onOutputGenerated'], 10);
        }
    }

    public function onPluginsInitialized()
    {
        // set defaults
        if ($this->config->isEmpty('plugins.config.feediting.contentSegment_WrapperPrefix')) {
            $this->config->set('plugins.config.feediting.contentSegment_WrapperPrefix', 'placeholder-');
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
        $this->userEditor = $this->config->get('plugins.config.feediting.editor');
        if (
            $this->userEditor == 'UserEditor'
            && isset($_GET['editor'])
            && in_array($_GET['editor'], $this->editorOptions)
        ){
            $editor = array_flip($this->editorOptions);
            @$_SESSION['NWeditor'] = $editor[$_GET['editor']];
        }
        $this->userEditor = @$_SESSION['NWeditor'];

        // set editor
        switch ($this->userEditor) {
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

    }

    public function getConfig()
    {
        $config = $this->config->toArray();
        return $config['plugins']['config']['feediting'];
    }

    public function includeAfterBodyStarts($uri)
    {
        $matches = array(1 => '<body>'); // set default match, overwritten if regex finds something
        preg_match('/(<body[^>]*>)/', $this->response->getContent(), $matches);
        $this->includeIntoTag($matches[1], $uri);
    }

    private function includeIntoTag($tag = null, $uri)
    {
        if (empty($tag)) {
            return;
        }

        if (!isset($this->replace_pairs[$tag])) {
            $this->replace_pairs[$tag] = $tag;
        }

        if (substr($uri, 0, 1) == '<') {

            // include a tag:
            if (substr($uri, 0, 2) == '</') {
                $this->replace_pairs[$tag] = $uri . PHP_EOL . $this->replace_pairs[$tag];
            } else {
                $this->replace_pairs[$tag] = $this->replace_pairs[$tag] . PHP_EOL . $uri . PHP_EOL;
            }
            return;
        }
        else {

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

    public function provideAsset($uri)
    {
        // include a path:
        $pathinfo = pathinfo($uri);
        $webdir = strtr(
            dirname($uri),
            [$this->alias->get('@plugin') => '']
        );

        if (strpos($webdir, '://') > 1) {

            $pathPrefix = '';
        }
        else {

            $pathPrefix = DS . 'assets';

            // copy src to assets
            $webpath = $pathPrefix . $webdir . DS . $pathinfo['basename'];
            $abspath = $this->alias->get('@web') . $webpath;
            if (!file_exists($abspath)) {
                @mkdir(dirname($abspath), 0777, true);
                copy($uri, $abspath);
            }
        }
        return [$pathPrefix, $webdir . DS, $pathinfo['filename'], '.' . $pathinfo['extension']];
    }

    public function includeBeforeBodyEnds($uri)
    {
        $this->includeIntoTag('</body>', $uri);
    }

    public function __get($attrib)
    {
        switch ($attrib) {
            case 'app':
            case 'path':
            case 'alias':
            case 'cmd':
            case 'replace_pairs':
            case 'remove_pairs':
                return $this->{$attrib};
                break;
            default:
                return false;
        }
    }

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
                default:
                    return $this->{$funcname}($args[0]);
            }
        } else {
            return;
        }
    }

    protected function onPageLoaded(\Herbie\Page $page)
    {
        if(!$this->isRealPage($page)) return;

        $this->recursivePageLoads++;
        if (
            $this->isEditable($page)
            && $this->recursivePageLoads <= 1
        ) {

            // Disable Caching while editing
            DI::get('Twig')->getEnvironment()->setCache(false);

            $this->page = $page;

            if(
                isset($_REQUEST['editor'])
                && 'iframe' == $_REQUEST['editor']
                && 'default.html' == $page->layout
            ){
                $this->page->layout = 'widgets/block.html';
            }

            $this->cmd  = @$_REQUEST['cmd'];

            $segmentId = (isset($_REQUEST['segmentid'])) ? $_REQUEST['segmentid'] : '0';
            $_twigify = ($this->loadEditableSegments() == 'twigify') ? true : false;
            if(
                isset($_REQUEST['cmd'])
                && in_array($segmentId, array_keys($this->editableContent))
                && is_subclass_of($this->editableContent[$segmentId],
                    '\\herbie\plugin\\feediting\\classes\\FeeditableContent'
                )
                && is_callable([$this->editableContent[$segmentId], $_REQUEST['cmd']])
            ){
                $this->cmd = $this->editableContent[$segmentId]->{$this->cmd}();
            }
            $this->editPage($_twigify);
        }
    }

    private function isRealPage($page = null){
        if($page && stripos($page->getPath(), '@plugin')!==false){
            $this->isRealPage = false;
        }
        return $this->isRealPage;
    }

    private function isEditable(\Herbie\Page $page)
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

    private function loadEditableSegments()
    {
        $this->segments = $this->page->getSegments();

        // set default segment for a blank page
        if (!array_key_exists('0', $this->segments)) {
            $this->segments = array_merge(array('0' => PHP_EOL), $this->segments);
        }

        foreach ($this->segments as $segmentId => $segmentContents)
        {
            // special case: segment with "grid-elements"
            preg_match_all($this->subsegment_match, $segmentContents, $captured);
            if ($this->parseSubsegments && count($captured['grid']) > 0) {

                // grid(s) found, build a "segmented" segment
                foreach ($captured['grid'] as $gid => $g)
                {
                    // make the text preceding the grid editable
                    $offset = $this->buildEditableSegment($segmentId, $captured['beforegrid'][$gid], 0, true);

                    // append the gridstart as uneditable text
                    $this->segments[$segmentId][] = $captured['gridstart'][$gid];

                    $cols = preg_split('/\\n+--\\n+/', $captured['gridcontent'][$gid]);
                    foreach ($cols as $col => $colcontent)
                    {
                        if ($col > 0) {
                            // append the grid-delimiter as uneditable text
                            $this->segments[$segmentId][] = PHP_EOL . '--' . PHP_EOL;
                        }
                        // append the contents as editable text
                        $offset = $this->buildEditableSegment($segmentId, $colcontent, $offset, true);
                    }

                    // append the gridend as uneditable text
                    $this->segments[$segmentId][] = PHP_EOL . $captured['gridend'][$gid] . PHP_EOL;
                }
            }
            else {
                $this->buildEditableSegment($segmentId, $segmentContents);
            }
        }

        // use one of the editors for common tasks
        $anyEditables = array_merge($this->editableContent, $this->lateEditableContent);
        $anyEditable = reset($anyEditables);
        while(
            !is_a($anyEditable, 'herbie\\plugin\\feediting\\classes\\FeeditableContent')
            && $anyEditable = next($anyEditables)
        ){
            ;//donothing
        }
        return $anyEditable->getSegmentLoadedMsg();
    }

    private function buildEditableSegment($segmentId, $content = '', $offset = 0, $buildSubsegment = false)
    {
        $contentEditor = "herbie\\plugin\\feediting\\classes\\{$this->editor}Content";

        if ($buildSubsegment) {

            if (!is_array($this->segments[$segmentId])) {
                $this->segments[$segmentId] = [];
            }
            // push the grid back into the segment, but replace editor-contents AFTER 'renderContent' is triggered
            $subsegmentId = sprintf($this->subsegmentid_format, $segmentId, count($this->segments[$segmentId]));
            $this->lateEditableContent[$subsegmentId] = new $contentEditor($this, $this->page->format, $subsegmentId);
            $offset = $this->lateEditableContent[$subsegmentId]->setContentBlocks($content, $offset);

            $this->segments[$segmentId][] = sprintf($this->subsegment_placeholder,$subsegmentId);
            // Register a "pseudo"-segment, needed for initializing the js-editors
            $this->segments[$subsegmentId] = true;
        }
        else {

            $this->editableContent[$segmentId] = new $contentEditor($this, $this->page->format, $segmentId);
            $this->editableContent[$segmentId]->setContentBlocks($content);
            $this->segments[$segmentId] = $this->editableContent[$segmentId]->getSegment();
        }
        return $offset;
    }

    private function editPage($_twigify = false)
    {
        switch ($this->cmd) {

            case 'save':
            case 'saveAndReturn':

                if (isset($_POST['id'])) {

                    $changed = $this->collectChanges();

                    if ($changed['segmentid'] !== false) {

                        $fheader = $this->getContentfileHeader();
                        $fh = fopen($this->path, 'w');
                        fputs($fh, $fheader);

                        foreach ($this->segments as $segmentId => $segmentContents)
                        {
                            if($this->recognizePseudoSegmentById($segmentId)){
                                continue;
                            }

                            $_modifiedContent[$segmentId] = '';

                            if ($segmentId != '0' && $segmentContents !== true) {
                                fputs($fh, PHP_EOL . "--- {$segmentId} ---" . PHP_EOL);
                            }

                            if ($this->parseSubsegments && is_array($segmentContents)) {

                                foreach ($segmentContents as $subsegmentId => $subsegmentContent)
                                {
                                    $subsegmentId = sprintf($this->subsegmentid_format, $segmentId, $subsegmentId);

                                    // search for responsible editors
                                    if (in_array($subsegmentId, array_keys($this->lateEditableContent))) {

                                        $_modifiedContent[$segmentId] .= $this->renderRawContent(
                                            $this->lateEditableContent[$subsegmentId]->getSegment(false),
                                            $this->lateEditableContent[$subsegmentId]->getFormat(),
                                            true
                                        );
                                    }
                                    elseif (in_array($subsegmentId, array_keys($this->editableContent))) {

                                        $_modifiedContent[$segmentId] .= $this->renderRawContent(
                                            $this->editableContent[$subsegmentId]->getSegment(false),
                                            $this->editableContent[$subsegmentId]->getFormat(),
                                            true
                                        );
                                    }
                                    else {
                                        $_modifiedContent[$segmentId] .= $subsegmentContent;
                                    }
                                }
                            }
                            else {
                                $_modifiedContent[$segmentId] = $this->renderRawContent(
                                    $this->editableContent[$segmentId]->getSegment(false),
                                    $this->editableContent[$segmentId]->getFormat(),
                                    true
                                );
                            }
                            fputs($fh, $_modifiedContent[$segmentId]);
                        }
                        fclose($fh);
                    }

                    // return early or reload (recalculate block-indexes)
                    if($this->cmd == 'saveAndReturn') return;
                    else $this->cmd = 'reload';

                    $this->page->load($this->page->getPath());
                    $_twigify        = ($this->loadEditableSegments() == 'twigify') ? true : false;
                    $editableContent = isset($this->lateEditableContent[$changed['segmentid']])
                        ? 'lateEditableContent'
                        : 'editableContent';

                    if (!$this->{$editableContent}[$changed['segmentid']]->reloadPageAfterSave) {

                        // prepare partial content for ajax-request
                        $changed_segment = $this->{$editableContent}[$changed['segmentid']]->getSegment();

                        // make changed content editable again, push it into page
                        $this->page->setSegments(
                            [$changed['segmentid'] => $this->renderEditableContent(
                                $changed['segmentid'],
                                $changed_segment,
                                $changed['contenttype'],
                                $_twigify
                            )]
                        );

                        // get segment from page
                        $segment = $this->page->getSegment($changed['segmentid']);

                        // filter changed content through all other plugins
                        $content = Hook::trigger(
                            Hook::FILTER,
                            'renderContent',
                            $segment->string,
                            $this->page->getData()
                        );

                        // dont reload but render only this 'partial'
                        die($content);
                    }
                    else {

                        // make all segments editable again for full page-reload
                        foreach ($this->segments as $id => $_segment) {
                            $this->segments[$id] = $this->renderEditableContent($id, $_segment, 'markdown', $_twigify);
                        }
                        $this->page->setSegments($this->segments);
                        break;
                    }
                }
                break;

            case 'bypass':
                break;

            default:
                foreach ($this->segments as $id => $_segment)
                {
                    if ($this->parseSubsegments && is_array($_segment)) {

                        $this->segments[$id] = '';
                        foreach ($_segment as $subCtr => $_subsegment)
                        {
                            $_subsegmentid = sprintf($this->subsegmentid_format, $id, $subCtr);

                            if (
                                array_key_exists($_subsegmentid, $this->editableContent)
                                && is_subclass_of($this->editableContent[$_subsegmentid],
                                    'herbie\plugin\feediting\classes\FeeditableContent'
                                )
                            ) {

                                $this->segments[$id] .= $this->renderEditableContent(
                                    $_subsegmentid,
                                    $_subsegment,
                                    'markdown',
                                    $_twigify
                                );
                                // Register a "pseudo"-segment, needed for initializing the js-editors
                                $this->segments[$_subsegmentid] = true;
                            }
                            else {

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

    private function collectChanges()
    {

        $this->replace_pairs = [];
        $anyEditor           = reset($this->editableContent);
        $posted              = $anyEditor->decodeEditableId($_POST['id']);
        $editableContent     = ($this->parseSubsegments && isset($this->lateEditableContent[$posted['segmentid']]))
            ? 'lateEditableContent'
            : 'editableContent';
        $ret                 = [
            'elemid' => false,
            'segmentid' => false,
            'contenttype' => false
        ];

        if ( !isset($this->{$editableContent}[$posted['segmentid']])) return $ret;

        if ( $this->{$editableContent}[$posted['segmentid']]->collectAllChanges === true) {

            $doEditableContents = $this->parseSubsegments ? ['editableContent','lateEditableContent'] : ['editableContent'];

            foreach($doEditableContents as $editableContent)
            {
                foreach ($this->{$editableContent} as $segmentId => $segmentContent)
                {
                    $elemId = $segmentContent->encodeEditableId($segmentId);
                    $subElemId = false;

                    if (preg_match('/(.*)\[(.*)\]/', $elemId, $test)) {
                        list($domId, $elemId, $subElemId) = $test;
                    }

                    if ($subElemId !== false && isset($_POST[$elemId][$subElemId])) {

                        if (!$segmentContent->setContentBlockById($segmentId, (string)$_POST[$elemId][$subElemId])) {
                            return $ret;
                        }
                    }
                    elseif (isset($_POST[$elemId])) {

                        if (!$segmentContent->setContentBlockById($elemId, (string)$_POST[$elemId])) {
                            return $ret;
                        }
                    }
                }
            }
        }
        else {

            if (!$this->{$editableContent}[$posted['segmentid']]->setContentBlockById($posted['elemid'], (string)$_POST['value'])) {
                return $ret;
            }
        }

        $ret['elemid'] = $posted['elemid'];
        $ret['segmentid'] = $posted['segmentid'];
        $ret['contenttype'] = $posted['contenttype'];

        return $ret;
    }

    private function getContentfileHeader()
    {
        // set current path
        $this->path = $this->alias->get($this->page->getPath());

        // read page's header
        $fh = fopen($this->path, 'r');
        if ($fh) {
            $currline = 0;
            $fheader = '';
            $fbody = '';
            while (($buffer = fgets($fh)) !== false) {
                $fpart = isset($fpart) ? $fpart : 'header';
                ${'f' . $fpart} .= $buffer;
                $currline++;
                if ($currline > 1 && strpos($buffer, '---') !== false) {
                    $fpart = 'body';
                    break; // don't break, if full body is needed!
                }
            }
        }
        fclose($fh);

        return $fheader;
    }

    private function recognizePseudoSegmentById($segmentId)
    {
        parse_str($segmentId, $arr);
        $test = reset($arr);
        return (is_array($test)) ? true : false;
    }

    private function renderRawContent($content, $format, $stripLF = false)
    {
        $ret = strtr(
            $content,
            [
                constant(strtoupper($format) . '_EOL') => $stripLF ? '' : PHP_EOL,
                'MARKDOWN_EOL' => PHP_EOL
            ]
        );
        $ret = strtr($ret, $this->remove_pairs);
        return $ret;
    }

    private function renderEditableContent($contentId, $content, $format, $twigify = false)
    {
        $ret = '';
        $content = $this->renderContent($contentId, $content, $format, $twigify);

        if ($this->parseSubsegments && is_array($content)) {

            foreach ($content as $subcontentId => $subcontent)
            {
                $subsegmentId = sprintf($this->subsegmentid_format, $contentId, $subcontentId);
                if (isset($this->editableContent[$subsegmentId])) {

                    $ret .= $this->editableContent[$subsegmentId]->getEditableContainer(
                        $subsegmentId,
                        strtr($subcontent, $this->replace_pairs)
                    );

                    // Register a "pseudo"-segment, needed for initializing the js-editors
                    $this->segments[$subsegmentId] = true;
                }
                else {
                    $ret .= strtr($subcontent, $this->replace_pairs);
                }
            }
        }
        elseif (isset($this->editableContent[$contentId])) {

            $ret = $this->editableContent[$contentId]->getEditableContainer(
                $contentId,
                strtr($content, $this->replace_pairs)
            );
        }
        return $ret;
    }

    private function renderContent($contentId, $content, $format, $twigify = false)
    {
        if ($twigify && !empty($content)) {

            //$content = DI::get('Twig')->renderString($content);
            $content = strtr($content, array(constant(strtoupper($format) . '_EOL') => PHP_EOL));
            $content = Hook::trigger(Hook::FILTER, 'renderContent', $content, $this->page->getData());
        }
        return $content;
    }

    protected function setPageSegments()
    {
        $segments = [];
        $segment_register = [];
        foreach ($this->segments as $segmentId => $editableContainer) {
            $segments[$segmentId] = $editableContainer;
            // register compound segments (i.e. with subsegments)
            parse_str($segmentId, $register);
            $segment_register = $register + $segment_register;
        }
        if($this->parseSubsegments){
            foreach ($segment_register as $segmentId => $v ) {
                if(!is_array($segment_register[$segmentId])){
                    $editableContainer    = $segments[$segmentId];
                    $containerPlaceholder = sprintf($this->subsegment_placeholder,$segmentId);
                    $segments[$segmentId] = $containerPlaceholder;
                    $this->replace_pairs[$containerPlaceholder] = $editableContainer;
                }
            }
        }
        $this->page->setSegments($segments);
        $this->page->nocache = true;
    }

    protected function onOutputGenerated($response)
    {
        if(!$this->isRealPage()) return;

        $this->response = $response;
        $this->self = $this->config->get('plugins.path') . '/feediting/';

        if ('UserEditor' == $this->config->get('plugins.config.feediting.editor')) {
            $options = '';
            foreach ($this->editorOptions as $editor => $option) {
                $options .= '<a href="?editor=' . $option . '" '.(@$_SESSION['NWeditor'] == $editor ? 'style="color:white" class="selected"':'').'>' . $option . '</a>';
            }
            $this->includeIntoAdminpanel(
                '<div class="feeditingpanel"><a name="FeditableContent"></a>' . $options . '</div>'
            );
        }
        $this->includeIntoHeader($this->self . 'assets/css/feediting.css');
        $this->getEditablesCssConfig($this->self);
        $this->getEditablesJsConfig($this->self);

        $content = strtr($response->getContent(), $this->replace_pairs);

        if($this->parseSubsegments)
        {
            // replace late-editor-contents at last
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

    public function includeIntoAdminpanel($html)
    {
        $adminpanelTag = '<div class="adminpanel">';
        $this->replace_pairs[$adminpanelTag] = '<div class="adminpanel">' . $html;
    }

    public function includeIntoHeader($uri)
    {
        $this->includeIntoTag('</head>', $uri);
    }

    private function getEditablesCssConfig($pluginPath)
    {
        $validKeys = array_keys($this->editableContent);
        $this->editableContent[@$validKeys[0]]->getEditablesCssConfig($pluginPath);
    }

    private function getEditablesJsConfig($pluginPath)
    {
        $validKeys = array_keys($this->editableContent);
        $this->editableContent[$validKeys[0]]->getEditablesJsConfig($pluginPath);
    }

    private function getReplacement($mark)
    {
        if (isset($this->replace_pairs[$mark])) {
            return $this->replace_pairs[$mark];
        } else {
            return false;
        }
    }

    private function setReplacement($mark, $replacement)
    {
        $this->replace_pairs[$mark] = $replacement;
        $this->remove_pairs[$mark] = '';
    }

    private function defineLineFeed($format, $eol)
    {
        $FORMAT_EOL          = $this->getLineFeedMarker($format);
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

    public function getLineFeedMarker($format, $editable = false)
    {
        return ($editable ? 'EDITABLE_' : '') . strtoupper($format) . '_EOL';
    }
}

(new FeeditingPlugin)->install();