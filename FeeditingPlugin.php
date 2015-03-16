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

use Twig_Loader_String;
use Symfony\Component\HttpFoundation\Session\Session;

class FeeditingPlugin extends \Herbie\Plugin
{
    protected $config = [];

    protected $authenticated = false;

    protected $editableSegments = [];

    protected $replace_pairs = [];

    protected $remove_pairs = [];

    protected $editableContent = [];

    protected $session;

    private $editor = 'Feeditable';

    private $cmd;

    public function __construct(\Herbie\Application $app)
    {
        parent::__construct($app);

        $this->authenticated = $this->isAuthenticated();

        // set defaults
        $this->config->set('plugins.config.feediting.contentSegment_WrapperPrefix', 'placeholder-');
        $this->config->set('plugins.config.feediting.editable_prefix', 'editable_');
        $this->config->set('plugins.config.feediting.contentBlockDimension', 100);

        // set editor
        switch($this->config->get('plugins.config.feediting.editor')){
            case 'Htmlforms':
                $this->editor = 'Feeditable';
                break;
            case 'SirTrevor':
                $this->editor = 'SirTrevor';
                break;
            default:
                $this->editor = 'Jeditable';
        }

    }

    // fetch markdown-contents for jeditable
    protected function onPageLoaded(\Herbie\Event $event )
    {
        // Disable Caching while editing
        $this->app['twig']->environment->setCache(false);

        $this->alias = $this->app['alias'];

        $this->path = $this->alias->get($this->app['menu']->getItem($this->app['route'])->getPath());

        $this->page = $event->offsetGet('page');
        $this->page->setLoader(new \Herbie\Loader\PageLoader($this->alias));
        $this->page->load($this->app['urlMatcher']->match($this->app['route'])->getPath());

        $this->cmd = @$_REQUEST['cmd'];

        $_segmentid = ( isset($_REQUEST['segmentid']) ) ? $_REQUEST['segmentid'] : '0';
        $_twigify   = ( $this->loadEditableSegments()=='twigify' ) ? true : false;
        if( isset($_REQUEST['cmd']) && is_subclass_of($this->editableContent[$_segmentid], '\\herbie\plugin\\feediting\\classes\\FeeditableContent') && is_callable(array($this->editableContent[$_segmentid], $_REQUEST['cmd']))){
            $this->cmd = $this->editableContent[$_segmentid]->{$this->cmd}();
        }

        $this->editPage($_twigify);
    }

    protected function onWidgetLoaded(\Herbie\Event $event ){

        switch($this->cmd)
        {
            case 'saveWidget':

                // Load widget's specific layout
                $widgetTemplateDir = $event->offsetGet('widgetTemplateDir');
                $pageLoader = $event->offsetGet('pageLoader');
                $pageLoader->addPath($widgetTemplateDir);

                $this->cmd = 'saveAndReturn';
                $this->editableContent = [];
                $this->editWidget($event);
                // Reload changed content
                $this->page->load($this->path);

            case 'getWidgetByName':

                // Load widget's specific layout
                $widgetTemplateDir = $event->offsetGet('widgetTemplateDir');
                $pageLoader = $event->offsetGet('pageLoader');
                $pageLoader->addPath($widgetTemplateDir);

                $this->cmd = 'editWidget';
                $this->editWidget($event);
        }
    }

    protected function onWidgetGenerated(\Herbie\Event $event ){

        switch($this->cmd)
        {
            case 'editWidget':

                $widgetData = $event->offsetGet('widgetData');
                $this->cmd = 'renderWidget';

                // Widget's main template 'index.html' is included into feediting.html
                $this->app['menuItem']->setData(['layout'=>'feediting.html']);
                $this->app['page']->setData($widgetData['data']);
                $this->app['page']->setSegments($widgetData['segments']);


            default:
                return;
        }
    }

    protected function editWidget($event){

        // Disable Caching while editing
        $this->app['twig']->environment->setCache(false);

        $this->page  = $this->app['page'];
        $this->alias = $this->app['alias'];
        $this->path  = $this->alias->get($event->offsetGet('widgetPath'));

        $_segmentid = ( isset($_REQUEST['segmentid']) ) ? $_REQUEST['segmentid'] : '0';
        $_twigify   = ( $this->loadEditableSegments()=='twigify' ) ? true : false;

        $this->editPage($_twigify);
    }

    private function editPage($_twigify=false){

        switch($this->cmd)
        {
            case 'save':
            case 'saveAndReturn':

                if( isset($_POST['id']) )
                {
                    $changed = $this->collectChanges();

                    if( $changed['segmentid'] !== false )
                    {
                        $fheader = $this->getContentfileHeader();
                        $fh = fopen($this->path, 'w');
                        fputs($fh, $fheader);
                        foreach($this->segments as $segmentid => $_staticContent){
                            if( $segmentid != '0' ) {
                                fputs($fh, PHP_EOL."--- {$segmentid} ---".PHP_EOL);
                            }
                            $_modifiedContent[$segmentid] = $this->renderRawContent($this->editableContent[$segmentid]->getSegment(false), $this->editableContent[$segmentid]->getFormat(), true );
                            fputs($fh, $_modifiedContent[$segmentid]);
                        }
                        fclose($fh);
                    }

                    if($this->cmd == 'saveAndReturn') return;
                    $this->cmd == 'reload';

                    $this->page->load($this->app['urlMatcher']->match($this->app['route'])->getPath());
                    $_twigify   = ( $this->loadEditableSegments()=='twigify' ) ? true : false;

                    if($this->editableContent[$changed['segmentid']]->reloadPageAfterSave === true)
                    {
                        foreach($this->segments as $id => $_segment){
                            $this->segments[$id] = $this->renderEditableContent($id, $_segment, 'markdown', $_twigify);
                        }
                        $this->page->setSegments($this->segments);
                        break;
                    }
                    else // deliver partial content for ajax-request
                    {
                        $editable_segment = $this->editableContent[$changed['segmentid']]->getSegment();

                        // render feeditable contents
                        $this->page->setSegments(array(
                            $changed['segmentid'] => $this->renderEditableContent($changed['segmentid'], $editable_segment, $changed['contenttype'], $_twigify)
                        ));

                        die($this->app->renderContentSegment($changed['segmentid']));
                    }
                }
                break;

            case 'bypass':
                break;

            default:

                foreach($this->segments as $id => $_segment){
                    $this->segments[$id] = $this->renderEditableContent($id, $_segment, 'markdown', $_twigify);
                }
        }

        $this->page->setSegments($this->segments);
    }

    protected function isAuthenticated()
    {
        $ret = false;
        if(!@$_REQUEST['action']=='logout') $ret = (bool) @$_SESSION['_sf2_attributes']['LOGGED_IN'];
        return $ret;
    }

    protected function onOutputGenerated(\Herbie\Event $event )
    {
        $_app          = $event->offsetGet('app');
        $_response     = $event->offsetGet('response');
        $_plugins      = $_app['config']->get('plugins');
        $_plugin_path  = str_replace($_app['webPath'], '', $_plugins['path']).'/feediting/';

        $this->getEditablesCssConfig($_plugin_path);

        $this->getEditablesJsConfig($_plugin_path);

        $event->offsetSet('response', $_response->setContent(
            strtr($_response->getContent(), $this->replace_pairs)
        ));
    }

    private function includeIntoTag($tag=null, $tagOrPath)
    {
        if(empty($tag)) return;

        if(!isset($this->replace_pairs[$tag]))
            $this->replace_pairs[$tag] = $tag;

        if(substr( $tagOrPath, 0, 1 ) == '<')
        {
            // include a tag:
            if(substr( $tagOrPath, 0, 2 ) == '</')
                $this->replace_pairs[$tag] = $tagOrPath.PHP_EOL.$this->replace_pairs[$tag];
            else
                $this->replace_pairs[$tag] = $this->replace_pairs[$tag].PHP_EOL.$tagOrPath.PHP_EOL;

            return;
        }
        else
        {
            // include a path:
            $abspath    = $this->alias->get('@plugin');
            $dirname    = strtr(dirname($tagOrPath), array($abspath => ''));
            $filename   = basename($tagOrPath);
            $fileAtoms  = explode('.',basename($filename));
            if( strpos($dirname, '://') > 1 ) {
                $ref = 'src';
                $pathPrefix = '';
            } else {
                $ref = 'src';
                $pathPrefix = DIRECTORY_SEPARATOR.'assets';
            }

            switch(end($fileAtoms))
            {
                case 'css':
                    $tmpl = '<link rel="stylesheet" href="%s" type="text/css" media="screen" title="no title" charset="utf-8">';
                    break;

                case 'js':
                    $tmpl = '<script '.$ref.'="%s" type="text/javascript" charset="utf-8"></script>';
                    break;

                default:
                    return;
            }
        }
        $this->replace_pairs[$tag] = sprintf($tmpl, $pathPrefix.$dirname.DIRECTORY_SEPARATOR.$filename).PHP_EOL.$this->replace_pairs[$tag];
    }

    private function getReplacement($mark){
        if(isset($this->replace_pairs[$mark]))
            return $this->replace_pairs[$mark];
        else
            return false;
    }

    private function setReplacement($mark, $replacement){
        $this->replace_pairs[$mark] = $replacement;
        $this->remove_pairs[$mark] = '';
    }
    
    private function getEditablesCssConfig( $pluginPath ){
        return $this->editableContent[0]->getEditablesCssConfig( $pluginPath );
    }

    private function getEditablesJsConfig( $pluginPath ){
        return $this->editableContent[0]->getEditablesJsConfig( $pluginPath );
    }

    private function loadEditableSegments(){

        $this->segments = $this->page->getSegments();

        if( !array_key_exists('0', $this->segments) ){
            $this->segments = array_merge(array('0' => PHP_EOL), $this->segments);
        }

        foreach($this->segments as $segmentid => $_staticContent)
        {
            $contentEditor = "herbie\\plugin\\feediting\\classes\\{$this->editor}Content";
            $this->editableContent[$segmentid] = new $contentEditor($this, $this->page->format, $segmentid);

            if(trim($_staticContent)=='' || trim($_staticContent)==PHP_EOL)
            {
                $_staticContent = $this->editableContent[$segmentid]->editableEmptySegmentContent;
            }

            $this->editableContent[$segmentid]->setContent($_staticContent);
            $this->segments[$segmentid] = $this->editableContent[$segmentid]->getSegment();
        };

        return $this->editableContent[$segmentid]->getSegmentLoadedMsg();
    }

    /**
     * @param string $content
     * @param string $format, eg. 'markdown'
     * @return string
     */
    private function renderRawContent( $content, $format, $stripLF = false )
    {
        $ret = strtr($content, array( constant(strtoupper($format).'_EOL') => $stripLF ? '' : PHP_EOL ));
        $ret = strtr($ret, $this->remove_pairs);
        return $ret;
    }

    /**
     * @param string $content
     * @param string $format, eg. 'markdown'
     * @return string
     */
    private function renderEditableContent( $contentId, $content, $format, $twigify=false )
    {
        if($twigify) {

            $herbieLoader = $this->app['twig']->environment->getLoader();
            $this->app['twig']->environment->setLoader(new Twig_Loader_String());
            $twigged = $this->app['twig']->environment->render(strtr($content, array( constant(strtoupper($format).'_EOL') => PHP_EOL )));
            $this->app['twig']->environment->setLoader($herbieLoader);

            $formatter = \Herbie\Formatter\FormatterFactory::create($format);
            $ret = strtr($formatter->transform($twigged), $this->replace_pairs);

        } else {

            $content = strtr($content, $this->replace_pairs);
            $ret = strtr($content, array(PHP_EOL => ''));
        }

        return $this->editableContent[$contentId]->getEditableContainer($contentId, $ret);
    }

    private function defineLineFeed($format, $eol)
    {
        $FORMAT_EOL = strtoupper($format).'_EOL';
        // used for saving
        if(!defined($FORMAT_EOL)) define($FORMAT_EOL, $eol);
        // used in in-page-editor
        if(!defined('EDITABLE_'.$FORMAT_EOL)) define('EDITABLE_'.$FORMAT_EOL, $eol);

        $this->replace_pairs[$eol] = '';
        $this->remove_pairs[$eol] = '';
    }

    private function getContentfileHeader()
    {
        // read page's header
        $fh = fopen($this->path, 'r');
        if($fh) {
            $currline = 0;
            $fheader = '';
            $fbody = '';
            while( ($buffer = fgets($fh))!==false )
            {
                $fpart = isset($fpart) ? $fpart : 'header';
                ${'f'.$fpart} .= $buffer;
                $currline++;
                if( $currline > 1 && strpos($buffer, '---')!==false ){
                    $fpart = 'body';
                    break; // don't break, if full body is needed!
                }
            }
        }
        fclose($fh);

        return $fheader;
    }

    private function collectChanges(){

        if(!$this->editableContent[0]){
            return false;
        }

        $posted = $this->editableContent[0]->decodeEditableId($_POST['id']);
        $this->replace_pairs = [];

        if($this->editableContent[$posted['segmentid']]->collectAllChanges === true)
        {
            foreach($this->editableContent as $_segmentid => $_segmentcontent)
            {
                $_elemid = $this->editableContent[$_segmentid]->encodeEditableId($_segmentid);
                if(!$_POST[$_elemid] || !$this->editableContent[$_segmentid]->setContentBlockById($_elemid, (string) $_POST[$_elemid])){
                    return false;
                }
            }
        }
        else
        {
            if(!$this->editableContent[$posted['segmentid']]->setContentBlockById($posted['elemid'], (string) $_POST['value'])){
                return false;
            }
        }

        return array(
            'elemid'        => $posted['elemid'],
            'segmentid'     => $posted['segmentid'],
            'contenttype'   => $posted['contenttype']
        );
    }

    public function getConfig(){
        $config = $this->config->toArray();
        return $config['plugins']['config']['feediting'];
    }

    public function includeIntoHeader($tagOrPath){
        $this->includeIntoTag('</head>', $tagOrPath);
    }

    public function includeAfterBodyStarts($tagOrPath){

        $this->app['twig']->environment->setLoader(new Twig_Loader_String());
        $twiggedBody = $this->app['twig']->environment->render('<body class="{{ bodyclass() }}">');

        $this->includeIntoTag($twiggedBody, $tagOrPath);
    }

    public function includeBeforeBodyEnds($tagOrPath){
        $this->includeIntoTag('</body>', $tagOrPath);
    }

    public function __get($attrib){
        switch($attrib){
            case 'app':
            case 'path':
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
        if($this->authenticated === true && 'adminpanel'!=$this->app->getRoute()){
            switch(count($args)){
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
}