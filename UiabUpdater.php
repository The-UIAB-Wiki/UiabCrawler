<?php

require_once "UiabCrawler.php";

class ScpSiteUtils
{
    const ROLE_AUTHOR = 1;
    const ROLE_REWRITER = 2;
    const ROLE_TRANSLATOR = 3;

    public static function setContributors(KeepAliveMysqli $link, ScpPage $page, $role, $users)
    {
        $userIds = array();
        foreach ($users as $userId) {
            $userIds[] = (string)$userId;
        }
        $link->query(vsprintf("CALL SET_CONTRIBUTORS(%d, %d, '%s')", array($page->getId(), $role, implode(',', $userIds))));
    }

    // Get information about authorship overrides from attribution page and write it to DB
    public static function updateStatusOverridesEn(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        $html = null;
        WikidotUtils::requestPage('scp-wiki', 'attribution-metadata', $html, $logger);
        if (!$html) {
            return;
        }
        $doc = phpQuery::newDocument($html);
        $table = pq('div#page-content table.wiki-content-table', $doc);
        if (!$table) {
            return;
        }
        $list = array();
        $i = 0;
        foreach (pq('tr', $table) as $row) {
            if ($i > 0) {
                $pgName = strtolower(pq('td:first-child', $row)->text());
                $type = pq('td:nth-child(3)', $row)->text();
                if (!array_key_exists($pgName, $list)) {
                    $list[$pgName] = array();
                }
                if (!array_key_exists($type, $list[$pgName])) {
                    $list[$pgName][$type] = array();
                }
                $list[$pgName][$type][] = array(
                    'user' => pq('td:nth-child(2)', $row)->text(),
                    'date' => pq('td:last-child', $row)->text()
                );
            }
            $i++;
        }
        $doc->unloadDocument();
        if (!$pages) {
            $pages = new ScpPageList('scp-wiki');
            $pages->loadFromDB($link, $logger);
        }
        $saved = 0;
        $nonDefault = 0;
        foreach ($list as $pageName => $overrideTypes) {
            if (strpos($pageName, ':') !== FALSE) {
                $nonDefault++;
                continue;
            } else {
                $page = $pages->getPageByName($pageName);
                if (!$page) {
                    WikidotLogger::logFormat($logger, 'Overriden page "%s" not found', array($pageName));
                    continue;
                }
            }
            foreach ($overrideTypes as $type => $overrides) {
                $ovUsers = array();
                foreach ($overrides as $override) {
                    if ($override['user'] == 'Unknown Author') {
                        $userId = -1;
                    } else {
                        $userId = ScpUserDbUtils::selectUserIdByDisplayName($link, $override['user'], $logger);
                    }
                    if (!$userId) {
                        WikidotLogger::logFormat($logger, 'Overriden author "%s" not found', array($override['user']));
                        continue;
                    } else {
                        $ovUsers[] = $userId;
                    }
                }
                if (count($ovUsers) == 0) {
                    continue;
                }
                switch ($type) {
                    case 'rewrite':
                        self::setContributors($link, $page, self::ROLE_REWRITER, $ovUsers);
                        break;
                    case 'translator':
                        self::setContributors($link, $page, self::ROLE_TRANSLATOR, $ovUsers);
                        break;
                    case 'author':
                        self::setContributors($link, $page, self::ROLE_AUTHOR, $ovUsers);
                        break;
                    default:
                        WikidotLogger::logFormat($logger, 'Unknown role "%s" for page "%s"', array($type, $pageName));
                }
                $saved++;
            }
        }        
        WikidotLogger::logFormat($logger, "::: Author overrides updates, %d entries saved, %d non-defaults skipped (%d total) :::", array($saved, $nonDefault, count($list)));
    }

    //
    private static function updateAltTitlesFromPage(
        KeepAliveMysqli $link,
        ScpPageList $pages,
        $wiki,
        $listPage,
        $pattern,
        WikidotLogger $logger = null
    )
    {
        $html = null;
        WikidotUtils::requestPage($wiki, $listPage, $html, $logger);
        if (!$html) {
            return;
        }
        $doc = phpQuery::newDocument($html);
        $i = 0;
        foreach (pq('div#page-content li', $doc) as $row) {
            $a = pq('a', $row);
            $pageName = substr($a->attr('href'), 1);
            if (preg_match($pattern, $pageName)) {
                $altTitle = substr($row->textContent, strlen($a->text())+3);
                $page = $pages->getPageByName($pageName);
                if ($page) {                    
                    $page->setProperty('altTitle', $altTitle);
                    if ($page->getModified()) {                        
                        $page->saveToDB($link, $logger);
                        $i++;
                    }
                }
            }
        }
        $doc->unloadDocument();
        return $i;
    }

    // Update alternative titles for SCPs
    public static function updateAltTitlesEn(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        if (!$pages) {
            $pages = new ScpPageList('scp-wiki');
            $pages->loadFromDB($link, $logger);
        }
        $total = 0;
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki', 'scp-series', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki', 'scp-series-2', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki', 'scp-series-3', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki', 'scp-series-4', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki', 'scp-series-5', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki', 'scp-series-6', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki', 'joke-scps', '/scp-.+/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki', 'archived-scps', '/scp-\d{3,4}-arc/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki', 'scp-ex', '/scp-\d{3,4}-ex/i', $logger);
        WikidotLogger::logFormat($logger, 'Updated alternative titles for %d pages', [$total]);
    }
    
    public static function updateAltTitlesRu(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        if (!$pages) {
            $pages = new ScpPageList('scp-ru');
            $pages->loadFromDB($link, $logger);
        }
        $total = 0;
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'scp-list', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'scp-list-2', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'scp-list-3', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'scp-list-4', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'scp-list-5', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'scp-list-6', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'scp-list-ru', '/scp-\d{3,4}-ru/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'scp-list-fr', '/scp-\d{3,4}-fr/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'scp-list-jp', '/scp-\d{3,4}-jp/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'scp-list-es', '/scp-\d{3,4}-es/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'scp-list-pl', '/scp-\d{3,4}-pl/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'scp-list-de', '/scp-\d{3,4}-de/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'scp-list-j', '/scp-.+/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'archive', '/scp-\d{3,4}-.+/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ru', 'explained-list', '/scp-\d{3,4}-.+/i', $logger);
        WikidotLogger::logFormat($logger, 'Updated alternative titles for %d pages', [$total]);
    }
    
    public static function updateAltTitlesKr(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        if (!$pages) {
            $pages = new ScpPageList('scp-kr');
            $pages->loadFromDB($link, $logger);
        }
        $total = 0;
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-kr', 'scp-series', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-kr', 'scp-series-2', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-kr', 'scp-series-3', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-kr', 'scp-series-4', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-kr', 'scp-series-5', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-kr', 'scp-series-6', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-kr', 'scp-series-ko', '/scp-\d{3,4}-ko/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-kr', 'joke-scps', '/scp-.+/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-kr', 'joke-scps-ko', '/scp-.+/i', $logger);        
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-kr', 'scp-ex', '/scp-\d{3,4}-ex/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-kr', 'scp-ko-ex', '/scp-\d{3,4}-ko-ex/i', $logger);
        WikidotLogger::logFormat($logger, 'Updated alternative titles for %d pages', [$total]);
    }
    
    public static function updateAltTitlesCn(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        if (!$pages) {
            $pages = new ScpPageList('scp-wiki-cn');
            $pages->loadFromDB($link, $logger);
        }
        $total = 0;
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-cn', 'scp-series', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-cn', 'scp-series-2', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-cn', 'scp-series-3', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-cn', 'scp-series-4', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-cn', 'scp-series-5', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-cn', 'scp-series-6', '/scp-\d{3,4}/i', $logger);        
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-cn', 'scp-series-cn', '/scp-cn-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-cn', 'scp-series-cn-2', '/scp-cn-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-cn', 'joke-scps', '/scp-.+/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-cn', 'joke-scps-cn', '/scp-cn-.+/i', $logger);        
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-cn', 'scp-ex', '/scp-\d{3,4}-ex/i', $logger);
        WikidotLogger::logFormat($logger, 'Updated alternative titles for %d pages', [$total]);
    }
    
    public static function updateAltTitlesFr(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        if (!$pages) {
            $pages = new ScpPageList('fondationscp');
            $pages->loadFromDB($link, $logger);
        }
        $total = 0;
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondationscp', 'scp-series', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondationscp', 'scp-series-2', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondationscp', 'scp-series-3', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondationscp', 'scp-series-4', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondationscp', 'scp-series-5', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondationscp', 'scp-series-6', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondationscp', 'liste-francaise', '/scp-\d{3,4}-fr/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondationscp', 'joke-scps', '/scp-.+/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondationscp', 'scps-humoristiques-francais', '/scp-.+/i', $logger);        
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondationscp', 'scp-ex', '/scp-\d{3,4}-ex/i', $logger);
        WikidotLogger::logFormat($logger, 'Updated alternative titles for %d pages', [$total]);
    }
    
    public static function updateAltTitlesPl(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        if (!$pages) {
            $pages = new ScpPageList('scp-pl');
            $pages->loadFromDB($link, $logger);
        }
        $total = 0;
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pl', 'lista-eng', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pl', 'lista-eng-2', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pl', 'lista-eng-3', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pl', 'lista-eng-4', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pl', 'lista-eng-5', '/scp-\d{3,4}/i', $logger);        
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pl', 'lista-pl', '/scp-pl-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pl', 'joke', '/scp-.+/i', $logger);
        WikidotLogger::logFormat($logger, 'Updated alternative titles for %d pages', [$total]);
    }    

    public static function updateAltTitlesEs(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        if (!$pages) {
            $pages = new ScpPageList('lafundacionscp');
            $pages->loadFromDB($link, $logger);
        }
        $total = 0;
        $total += self::updateAltTitlesFromPage($link, $pages, 'lafundacionscp', 'scp-series', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'lafundacionscp', 'scp-series-2', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'lafundacionscp', 'scp-series-3', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'lafundacionscp', 'scp-series-4', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'lafundacionscp', 'scp-series-5', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'lafundacionscp', 'scp-series-6', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'lafundacionscp', 'serie-scp-es', '/scp-es-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'lafundacionscp', 'serie-scp-es-2', '/scp-es-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'lafundacionscp', 'serie-scp-es-3', '/scp-es-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'lafundacionscp', 'scps-humoristicos', '/scp-.+/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'lafundacionscp', 'scps-exs', '/scp-\d{3,4}-ex/i', $logger);
        WikidotLogger::logFormat($logger, 'Updated alternative titles for %d pages', [$total]);
    }

    
    public static function updateAltTitlesTh(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        if (!$pages) {
            $pages = new ScpPageList('scp-th');
            $pages->loadFromDB($link, $logger);
        }
        $total = 0;
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-th', 'scp-series', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-th', 'scp-series-2', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-th', 'scp-series-3', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-th', 'scp-series-4', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-th', 'scp-series-5', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-th', 'scp-series-th', '/scp-\d{3,4}-th/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-th', 'joke-scps', '/scp-.+/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-th', 'joke-scps-th', '/scp-.+/i', $logger);
        WikidotLogger::logFormat($logger, 'Updated alternative titles for %d pages', [$total]);
    }    
        
    public static function updateAltTitlesJp(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        if (!$pages) {
            $pages = new ScpPageList('scp-jp');
            $pages->loadFromDB($link, $logger);
        }
        $total = 0;
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-jp', 'scp-series', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-jp', 'scp-series-2', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-jp', 'scp-series-3', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-jp', 'scp-series-4', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-jp', 'scp-series-5', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-jp', 'scp-series-6', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-jp', 'scp-series-jp', '/scp-\d{3,4}-jp/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-jp', 'scp-series-jp-2', '/scp-\d{3,4}-jp/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-jp', 'scp-series-jp-3', '/scp-\d{3,4}-jp/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-jp', 'joke-scps', '/scp-.+/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-jp', 'joke-scps-jp', '/scp-.+/i', $logger);        
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-jp', 'scp-ex', '/scp-\d{3,4}-ex/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-jp', 'scp-jp-ex', '/scp-\d{3,4}-jp-ex/i', $logger);
        WikidotLogger::logFormat($logger, 'Updated alternative titles for %d pages', [$total]);
    }    
    
    public static function updateAltTitlesDe(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        if (!$pages) {
            $pages = new ScpPageList('scp-wiki-de');
            $pages->loadFromDB($link, $logger);
        }
        $total = 0;
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-de', 'scp-series', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-de', 'scp-series-2', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-de', 'scp-series-3', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-de', 'scp-series-4', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-de', 'scp-series-5', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-de', 'scp-series-6', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-de', 'scp-de', '/scp-\d{3,4}-de/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-de', 'joke-scps', '/scp-.+/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-wiki-de', 'scp-ex', '/scp-\d{3,4}-ex/i', $logger);
        WikidotLogger::logFormat($logger, 'Updated alternative titles for %d pages', [$total]);
    }        

    public static function updateAltTitlesIt(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        if (!$pages) {
            $pages = new ScpPageList('fondazionescp');
            $pages->loadFromDB($link, $logger);
        }
        $total = 0;
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondazionescp', 'scp-series', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondazionescp', 'scp-series-2', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondazionescp', 'scp-series-3', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondazionescp', 'scp-series-4', '/scp-\d{3,4}/i', $logger);       
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondazionescp', 'scp-series-5', '/scp-\d{3,4}/i', $logger);       
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondazionescp', 'scp-it-serie-i', '/scp-\d{3,4}-it/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondazionescp', 'joke-scps', '/scp-.+/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'fondazionescp', 'scp-ex', '/scp-\d{3,4}-ex/i', $logger);
        WikidotLogger::logFormat($logger, 'Updated alternative titles for %d pages', [$total]);
    }            

    public static function updateAltTitlesUa(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        if (!$pages) {
            $pages = new ScpPageList('scp-ukrainian');
            $pages->loadFromDB($link, $logger);
        }
        $total = 0;
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ukrainian', 'scp-series', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ukrainian', 'scp-series-2', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ukrainian', 'scp-series-3', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ukrainian', 'scp-series-4', '/scp-\d{3,4}/i', $logger);       
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ukrainian', 'scp-series-5', '/scp-\d{3,4}/i', $logger);       
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ukrainian', 'scp-series-6', '/scp-\d{3,4}/i', $logger);               
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ukrainian', 'scp-series-ua', '/scp-\d{3,4}-ua/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ukrainian', 'scp-list-j', '/scp-.+/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-ukrainian', 'explained-list', '/scp-\d{3,4}-ex/i', $logger);
        WikidotLogger::logFormat($logger, 'Updated alternative titles for %d pages', [$total]);
    }  

    public static function updateAltTitlesPt(
        KeepAliveMysqli $link,
        ScpPageList $pages = null,
        WikidotLogger $logger = null
    )
    {
        if (!$pages) {
            $pages = new ScpPageList('scp-pt-br');
            $pages->loadFromDB($link, $logger);
        }
        $total = 0;
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pt-br', 'scp-series', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pt-br', 'scp-series-2', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pt-br', 'scp-series-3', '/scp-\d{3,4}/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pt-br', 'scp-series-4', '/scp-\d{3,4}/i', $logger);       
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pt-br', 'scp-series-5', '/scp-\d{3,4}/i', $logger);               
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pt-br', 'series-1-pt', '/scp-\d{3,4}-pt/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pt-br', 'joke-scps', '/scp-.+/i', $logger);
        $total += self::updateAltTitlesFromPage($link, $pages, 'scp-pt-br', 'scp-ex', '/scp-\d{3,4}-ex/i', $logger);
        WikidotLogger::logFormat($logger, 'Updated alternative titles for %d pages', [$total]);
    }    
}

class ScpPagesUpdater
{
    // Database link
    protected $link;
    // Id of the wiki
    protected $siteId;
    // Logger    
    protected $logger;
    // List of pages from the database
    protected $pages;
    // List of users on the site
    //protected $users;
    // Total number of pages on the site
    protected $total;
    // Number of succesfully processed pages
    protected $updated = 0;
    // Number of saved pages
    protected $saved = 0;
    // Number of pages changed since the last updated
    protected $changed = 0;
    // List of pages retrieved from the site
    protected $sitePages;
    // Array to detect duplicating pages (redirects from several urls to a single page)
    protected $done = array();
    // Array of names to keep track of pages we failed to load from the site
    protected $failedPages = array();

    public function __construct(KeepAliveMysqli $link, $siteId, ScpPageList $pages, WikidotLogger $logger = null/*, ScpUserList $users = null*/)
    {
        $this->link = $link;
        $this->siteId = $siteId;
        $this->pages = $pages;
        //$this->users = $users;
        $this->logger = $logger;
    }

    // Helper function
    protected function saveUpdatingPage(ScpPage $page)
    {
        // But first, we need to add to DB users that aren't there yet
/*        foreach ($page->getRetrievedUsers() as $userId => $user) {
            $this->users->addUser($user);
            $listUser = $this->users->getUserById($userId);
            if ($listUser->getModified()) {
                $listUser->saveToDB($this->link, $this->logger);
            }
        }*/
        foreach ($page->getRetrievedUsers() as $userId => $user) {
            if ($user->getDeleted()) {
                $user->saveToDB($this->link, $this->logger);
            }                
        }
        // Now save the page
        return $page->saveToDB($this->link, $this->logger);
    }

    //
    protected function prepareUpdate()
    {
/*        if (!$this->users) {
            // Prepare list of users. We need it to add users retrieved along with pages.
            $this->users = new ScpUserList($this->pages->siteName);
            $this->users->loadFromDB($this->link, $this->logger);
        }*/
        WikidotLogger::logFormat($this->logger, "Before loading from DB: %d", array(memory_get_usage()));
        // Let's retrieve all pages from DB
        $this->pages->loadFromDB($this->link, $this->logger);
        WikidotLogger::logFormat($this->logger, "After loading from DB: %d", array(memory_get_usage()));
        $this->pages->retrieveCategories($this->logger);
        foreach ($this->pages->getCategories() as $id => $name) {
            ScpCategoryDbUtils::insert($this->link, $this->siteId, $id, $name, $this->logger);
        }                
        WikidotLogger::logFormat($this->logger, "Before retrieving list: %d", array(memory_get_usage()));
        // Get a list of pages from the site (only names)
        $this->sitePages = $this->pages->fetchListOfPages(null, $this->logger);
        WikidotLogger::logFormat($this->logger, "After retrieving list: %d", array(memory_get_usage()));
        $this->total = count($this->sitePages);
        $this->updated = 0;
        $this->saved = 0;
        $this->changed = 0;
        $this->failedPages = array();
        $this->done = array();
    }

    protected function finishUpdate()
    {
        $toDelete = [];
        // At this point our list will contain only failed pages
        // and pages that aren't on the site anymore
        // One last try to save pages that failed the first time - there shouldn't be many of them
        $this->pages->retrieveList($this->failedPages, true, $this->logger);
        foreach ($this->pages->iteratePages() as $page) {
            $id = $page->getId();
            if ($page->getStatus() == WikidotStatus::OK) {
                if (!isset($this->done[$id])) {
                    $this->done[$id] = true;
                    if ($page->getModified()) {
                        $this->changed++;
                        if ($this->saveUpdatingPage($page)) {
                            $this->saved++;
                        }
                    }
                } else {
                    $this->total--;
                }
            } else if ($page->getStatus() == WikidotStatus::NOT_FOUND && $id) {
                $toDelete[$id] = $page;
            } else if ($page->getStatus() == WikidotStatus::UNKNOWN && $id) {
                $page->retrievePageInfo();
                if ($page->getStatus() === WikidotStatus::NOT_FOUND || $page->getStatus() === WikidotStatus::OK && $page->getId() !== $id) {
                    $toDelete[$id] = $page;
                }
            }
            $this->updated++;
        }
        $deleted = count($toDelete);
        // Lastly delete pages that are not on site anymore
        foreach ($toDelete as $pageId => $page) {
            ScpPageDbUtils::delete($this->link, $pageId, $this->logger);
            WikidotLogger::logFormat($this->logger, "::: Deleting page %s (%d) :::", array($page->getPageName(), $pageId));
        }
        WikidotLogger::logFormat($this->logger, "::: Saved %d pages (%d changed, %d unique) :::", array($this->saved, $this->changed, $this->total));
        WikidotLogger::logFormat($this->logger, "::: Deleted %d pages :::", array($deleted));
    }

    protected function processPage(ScpPage $page, $success)
    {
        $id = $page->getId();
        if ($id) {
            if (!isset($this->done[$id])) {
                // If we retrieved everything successfully, add page to the list or copy information to the existing page on list
                if ($success) {
                    $this->pages->addPage($page);
                    $page = $this->pages->getPageById($id);
                    // Then save this page to DB
                    if ($page->getModified()) {
                        $this->changed++;
                        if ($this->saveUpdatingPage($page)) {
                            $this->saved++;
                        }
                    }
                    $this->updated++;
                    $this->done[$id] = true;
                } else {
                    // Otherwise, to the failed pages we go
                    $this->failedPages[] = $page->getPageName();
                }
            } else {
                $this->total--;
            }
            // Null all references to the page and free memory, unless it's in the failed list
            $this->pages->removePage($id);
        } else {
            // Otherwise, to the failed pages we go
            $this->failedPages[] = $page->getPageName();
        }
        // Logging our progress
        if ($this->updated % 100 == 0) {
            WikidotLogger::logFormat(
                $this->logger,
                "%d pages updated [%d kb used]...",
                array($this->updated, round(memory_get_usage()/1024))
            );
        }
    }

    // Process all the pages
    protected function processPages()
    {
        // Iterate through all pages and process them one by one
        for ($i = count($this->sitePages)-1; $i>=0; $i--) {
            $page = $this->sitePages[$i];
            // Maintain a list of pages we failed to retrieve so we could try again later
            if (!$page->retrievePageInfo($this->logger)) {
                $this->processPage($page, false);
                continue;
            }
            $good = true;
            if (!isset($this->done[$page->getId()])) {
                // Let's see if this page already exists in the database
                $oldPage = $this->pages->getPageById($page->getId());
                // Always have to retrieve votes because it's impossible to tell without it if they have changed
                if (!$page->retrievePageVotes($logger)) {
                    $this->processPage($page, false);
                    continue;
                }
                // If it's a new page or it was edited, we have to retrieve source and list of revisions
                if (!$oldPage || $page->getLastRevision() != $oldPage->getLastRevision() || $oldPage->getSource() == null || strlen($oldPage->getSource() < 10)) {
                    $good = $page->retrievePageHistory($this->logger) && $page->retrievePageSource($this->logger);
                }
            }
            $this->processPage($page, $good);
            if ($good) {
                unset($page);
                unset($this->sitePages[$i]);
            }
        }
    }

    // Load list from DB, update it from website and save changes back to DB
    public function go()
    {
        $this->prepareUpdate();
        $this->processPages();
        $this->finishUpdate();
    }
}

class ScpUsersUpdater
{
    // Database link
    protected $link;
    // Logger
    protected $logger;
    // Site
    protected $siteName;
    protected $siteId;
    // Number of pages in the list of members
    protected $pageCount = 0;
    // Number of users
    protected $total = 0;
    // Couldn't load entire list or just some pages. Do not save
    protected $failed = false;

    public function __construct(KeepAliveMysqli $link, $siteName, $siteId, WikidotLogger $logger = null)
    {
        $this->link = $link;
        $this->siteName = $siteName;
        $this->siteId = $siteId;
        $this->logger = $logger;
    }

    protected function prepareUpdate()
    {
        $membersHtml = null;
        //$this->failed = (WikidotUtils::requestPage($this->siteName, 'system:members', $membersHtml, $this->logger) !== WikidotStatus::OK);
        $this->failed = (WikidotUtils::requestModule($this->siteName, 'membership/MembersListModule', 0, [], $membersHtml, $this->logger) !== WikidotStatus::OK);
        // Get a list of pages from the site (only names)
        $this->pageCount = 0;
        $this->total = 0;        
        if (!$this->failed) {
            if ($membersHtml) {
                $this->pageCount = WikidotUtils::extractPageCount($membersHtml);
            }
        }
        ScpMembershipDbUtils::start($this->link, $this->siteId, $this->logger);
    }

    // Retrieve all the users
    protected function retrieveUsers()
    {
        $memberList = WikidotUtils::iteratePagedModule($this->siteName, 'membership/MembersListModule', 0, [], range(1, $this->pageCount), $this->logger);
        foreach ($memberList as $mlPage) {
            if ($mlPage) {
                $users = new ScpUserList($this->siteName, $this->siteId);
                $this->total += $users->addMembersFromListPage($mlPage, $this->logger);
                if ($this->total % 1000 == 0) {
                    WikidotLogger::logFormat(
                        $this->logger,
                        "%d members retrieved [%d kb used]...",
                        array($this->total, round(memory_get_usage()/1024))
                    );
                }
                $users->saveToDB($this->link, $this->logger, false);
                $users->saveMembershipToDBGreedy($this->link, $this->logger);
                unset($users);
            } else {
                $this->failed = true;
                return;
            }
        }
    }

    // Finish updating
    protected function finishUpdate()
    {
        if (!$this->failed) {
/*            $users = $this->webList->getUsers();
            foreach ($users as $userId => $usr) {
                $this->users->addUser($usr['User'], $usr['Date']);
            }
            $this->users->saveToDB($this->link, $this->logger);*/
        } else {
            WikidotLogger::log($this->logger, "ERROR: Failed to update list of members!");
        }
    }

    // Load list from DB, update it from website and save changes back to DB
    public function go()
    {
        $this->prepareUpdate();
        if (!$this->failed) {
            $this->retrieveUsers();            
        }
        // retrieveUsers can change $this->failed flag
        $this->finishUpdate();
    }
}

class ScpSiteUpdater
{
    protected function getUsersUpdaterClass()
    {
        return 'ScpUsersUpdater';
    }

    protected function getPagesUpdaterClass()
    {
        return 'ScpPagesUpdater';
    }

    protected function updateStatusOverrides($siteName, KeepAliveMysqli $link, ScpPageList $pages = null, WikidotLogger $logger = null)
    {
        if ($siteName == 'scp-wiki') {
            ScpSiteUtils::updateStatusOverridesEn($link, $pages, $logger);
        }        
        WikidotLogger::log($logger, "Updating page kinds...");
        if ($siteName == 'scp-wiki') {
            $link->query("CALL FILL_PAGE_KINDS_EN()");
        } else if ($siteName == 'scp-ru') {
            $link->query("CALL FILL_PAGE_KINDS_RU()");
        } else if ($siteName == 'fondationscp') {
            $link->query("CALL FILL_PAGE_KINDS_FR()");
        } else if ($siteName == 'scp-wiki-de') {
            $link->query("CALL FILL_PAGE_KINDS_DE()");
        } else if ($siteName == 'scp-kr') {
            $link->query("CALL FILL_PAGE_KINDS_KO()");
        }        
    }

    protected function updateAlternativeTitles($siteName, KeepAliveMysqli $link, ScpPageList $pages = null, WikidotLogger $logger = null)
    {
        switch ($siteName) {
            case 'scp-wiki':
                ScpSiteUtils::updateAltTitlesEn($link, $pages, $logger);
                break;
            case 'scp-ru':
                // Alt title is a part of title
                // ScpSiteUtils::updateAltTitlesRu($link, $pages, $logger);
                break;            
            case 'scp-kr':
                ScpSiteUtils::updateAltTitlesKr($link, $pages, $logger);
                break;            
            case 'scp-jp':
                ScpSiteUtils::updateAltTitlesJp($link, $pages, $logger);
                break;            
            case 'fondazionescp':
                ScpSiteUtils::updateAltTitlesIt($link, $pages, $logger);
                break;            
            case 'fondationscp':
                ScpSiteUtils::updateAltTitlesFr($link, $pages, $logger);
                break;            
            case 'lafundacionscp':
                ScpSiteUtils::updateAltTitlesEs($link, $pages, $logger);
                break;            
            case 'scp-th':
                ScpSiteUtils::updateAltTitlesTh($link, $pages, $logger);
                break;            
            case 'scp-pl':
                // Doesn't work due to formatting
                // ScpSiteUtils::updateAltTitlesPl($link, $pages, $logger);
                break;
            case 'scp-wiki-de':
                ScpSiteUtils::updateAltTitlesDe($link, $pages, $logger);
                break;            
            case 'scp-wiki-cn':
                ScpSiteUtils::updateAltTitlesCn($link, $pages, $logger);
                break;            
            case 'scp-ukrainian':
                ScpSiteUtils::updateAltTitlesUa($link, $pages, $logger);
                break;            
            case 'scp-pt-br':
                // Doesn't work due to formatting
                // ScpSiteUtils::updateAltTitlesPt($link, $pages, $logger);
                break;            
        }
    }

    // Load all data from site and save it to DB
    public function loadSiteData($siteName, KeepAliveMysqli $link, WikidotLogger $logger)
    {
        WikidotLogger::log($logger, "\n");
        WikidotLogger::logFormat($logger, "======= Starting the first indexation of %s.wikidot.com =======", array($siteName));
        $ul = new ScpUserList($siteName);
        $ul->retrieveSiteMembers($logger);
        $pl = new ScpPageList($siteName);
        $pl->retrievePages(null, 0, $logger);
        $i = 0;
        foreach($pl->iteratePages() as $page) {
            $page->retrievePageModules($logger);
            $ul->addUsersFromPage($page);
            $i++;
            if ($i % 100 == 0) {
               WikidotLogger::logFormat($logger, "%d pages done...", array($i));
            }
        }
        $ul->saveToDB($link, $logger);
        $pl->saveToDB($link, $logger);
        WikidotLogger::logFormat($logger, "======= The first indexation of %s.wikidot.com has finished =======", array($siteName));
    }

    // Update data for a site from web
    public function updateSiteData($siteName, KeepAliveMysqli $link, WikidotLogger $logger)
    {
        WikidotLogger::log($logger, "\n");
        WikidotLogger::logFormat($logger, "======= Updating data for %s.wikidot.com =======", array($siteName));
        if ($dataset = $link->query("SELECT WikidotId FROM sites WHERE WikidotName='$siteName'")) {
            if ($row = $dataset->fetch_assoc()) {
                $siteId = (int) $row['WikidotId'];
            }
        }
        if (!isset($siteId)) {
            WikidotLogger::log($logger, "Error: Failed to retrieve site id from database.");
            return;
        }
        //$ul = new ScpUserList($siteName);
        //$ul->loadFromDB($link, $logger);
        $link->begin_transaction();
        $updaterClass = $this->getUsersUpdaterClass();
        $userUpdater = new $updaterClass($link, $siteName, $siteId, $logger);
        $userUpdater->go();
        unset($userUpdater);
        //$ul->updateFromSite($logger);
        //$ul->saveToDB($link, $logger);
        $pl = new ScpPageList($siteName);
        $updaterClass = $this->getPagesUpdaterClass();
        $pageUpdater = new $updaterClass($link, $siteId, $pl, $logger);
        $pageUpdater->go();
        unset($pageUpdater);
        $pl = new ScpPageList($siteName);
        $pl->loadFromDB($link, $logger);
        $this->updateStatusOverrides($siteName, $link, $pl, $logger);
        WikidotLogger::log($logger, "Updating alternative titles...");
        $this->updateAlternativeTitles($siteName, $link, $pl, $logger);
        $link->query("UPDATE sites SET LastUpdate = Now() WHERE WikidotId = '$siteId'");
        WikidotLogger::log($logger, "Updating user activity...");
        $link->query("CALL UPDATE_USER_ACTIVITY('$siteId')");
        WikidotLogger::log($logger, "Updating page summaries...");
        $link->query("CALL UPDATE_PAGE_SUMMARY('$siteId')");
        WikidotLogger::log($logger, "Updating site stats...");
        $link->query("CALL UPDATE_SITE_STATS('$siteId')");
        WikidotLogger::logFormat($logger, "Peak memory usage: %d kb", array(round(memory_get_peak_usage()/1024)));
        WikidotLogger::logFormat($logger, "======= Update %s.wikidot.com has finished =======", array($siteName));        
        $link->commit();        
    }
}