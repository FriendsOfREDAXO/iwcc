<?php

class consent_manager_cache
{

    private $domains = [];
    private $cookiegroups = [];
    private $cookies = [];
    private $texts = [];
    private $config = [
        'domains' => [],
        'cookiegroups' => [],
        'cookies' => [],
        'texts' => [],
        'cacheLogId' => 0
    ];

    public static function write(rex_extension_point $ep)
    {
        $form = $ep->getParams()['form'];
        if (!in_array($form->getTableName(), consent_manager_config::getTables())) {
            return true;
        }
        $cache = new self();
        $cache->writeCache();
    }

    private function writeCache()
    {
        $addon = rex_addon::get('consent_manager');
        $configFile = $addon->getDataPath('config.json');
        $this->fetchData();
        foreach (rex_clang::getAllIds() as $clangId) {
            $this->prepareData($clangId);
            $this->setConfig($clangId);
        }
        $db = rex_sql::factory();
        $db->setTable(rex::getTable('consent_manager_cache_log'));
        $db->setValue('consent', json_encode($this->config));
        $user = rex::getUser() ? rex::getUser()->getLogin() : 'forceCache';
        $db->setValue('createuser', $user);
        $db->setRawValue('createdate', 'NOW()');
        $db->insert();
        $this->config['cacheLogId'] = $db->getLastId();
        if (!rex_file::putCache($configFile, $this->config)) {
            rex_logger::logError(1, rex_i18n::msg('consent_manager_cache_write_failed'), __FILE__, __LINE__);
        }
    }

    private function fetchData()
    {
        $db = rex_sql::factory();
        $db->setTable(rex::getTable('consent_manager_domain'));
        $db->select('*');
        $domains = $db->getArray();
        foreach ($domains as $v) {
            $this->domains[$v['id']] = $v;
        }

        $db = rex_sql::factory();
        $db->setTable(rex::getTable('consent_manager_cookiegroup'));
        $db->setWhere('1=1 ORDER BY prio ASC');
        $db->select('*');
        $cookiegroups = $db->getArray();
        foreach ($cookiegroups as $v) {
            $this->cookiegroups[$v['clang_id']][$v['uid']] = $v;
        }

        $db = rex_sql::factory();
        $db->setTable(rex::getTable('consent_manager_cookie'));
        $db->select('*');
        foreach ($db->getArray() as $v) {
            $this->cookies[$v['clang_id']][$v['uid']] = $v;
        }
        $db = rex_sql::factory();
        $db->setTable(rex::getTable('consent_manager_text'));
        $db->select('*');
        foreach ($db->getArray() as $v) {
            $this->texts[$v['clang_id']][$v['uid']] = $v['text'];
        }
    }

    private function prepareData($clangId)
    {
        foreach ($this->cookies[$clangId] as $uid => $cookie) {
            $defs = [];
            foreach ((array)rex_string::yamlDecode($cookie['definition']) as $k => $v) {
                $defs[$k]['cookie_name'] = $v['name'];
                $defs[$k]['cookie_lifetime'] = $v['time'];
                $defs[$k]['description'] = $v['desc'];
            }
            $cookie['definition'] = $defs;
            $cookie['script'] = base64_encode($cookie['script']);
            $this->cookies[$clangId][$uid] = $cookie;
        }

        foreach ($this->cookiegroups[$clangId] as $uid => $cookiegroup) {
            $cookie_uids = array_filter(explode('|', $cookiegroup['cookie']));
            if ($cookiegroup['required'] == '|1|') {
                $cookie_uids[] = 'consent-manager';
            }
            $this->cookiegroups[$clangId][$uid]['cookie_uids'] = array_merge(array_filter(array_unique($cookie_uids)));
            $domainIds = array_filter(explode('|', $cookiegroup['domain']));
            foreach ($domainIds as $domainId) {
                if (isset($this->domains[$domainId])) {
                    $this->domains[$domainId]['cookiegroups'][] = $uid;
                }
            }
        }
    }

    private function setConfig($clangId)
    {
        foreach ($this->cookiegroups[$clangId] as $v) {
            $this->config['cookiegroups'][$v['clang_id']][$v['uid']] = $v;
        }
        foreach ($this->cookies[$clangId] as $v) {
            $this->config['cookies'][$v['clang_id']][$v['uid']] = $v;

        }
        foreach ($this->domains as $v) {
            $this->config['domains'][$v['uid']] = $v;
        }
        $this->config['texts'][$clangId] = $this->texts[$clangId];
        $this->config['majorVersion'] = rex_addon::get('consent_manager')->getVersion('%s');
    }

    public static function forceWrite()
    {
        $cache = new self();
        $cache->writeCache();
    }

    public static function read()
    {
        $addon = rex_addon::get('consent_manager');
        $configFile = $addon->getDataPath('config.json');

        return rex_file::getCache($configFile);
    }
}