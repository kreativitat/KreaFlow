<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License,
 * or (at your option) any later version.
 */

/** Read-only bridge to an explicitly bound KreaSynch source. Credentials never reach the browser. */
class KreaFlowKitchenClient
{
    /** @var int */
    private $entity;

    public function __construct($entity)
    {
        $this->entity = (int) $entity;
    }

    /** The private file is selected by deployment, never by an HTTP parameter or a fallback entity. */
    public function read($since = '', $window = null)
    {
        $path = getenv('KREAFLOW_KITCHEN_CONFIG_FILE') ?: getDolGlobalString('KREAFLOW_KITCHEN_CONFIG_FILE');
        $all = $path && is_readable($path) ? json_decode(file_get_contents($path), true) : null;
        $config = is_array($all) ? ($all[(string) $this->entity] ?? null) : null;
        if (!is_array($config) || empty($config['enabled'])) {
            throw new KreaFlowApiException('Kitchen integration is not configured for this company.', 503);
        }
        $base = rtrim((string) ($config['url'] ?? ''), '/');
        $url = parse_url($base);
        $token = (string) ($config['token'] ?? '');
        $source = (string) ($config['source_id'] ?? '');
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $token)
            || !preg_match('/^[a-f0-9-]{36}$/D', $source)) {
            throw new KreaFlowApiException('Kitchen integration configuration is invalid.', 503);
        }
        $query = $since !== '' ? array('since' => $since) : array();
        if ($window) {
            $query['stations'] = $window['stations'] ?? '0';
            $query['window_start'] = $window['window_start'];
            $query['window_end'] = $window['window_end'];
        }
        $response = $this->fetch($base.'/integrations/kreaflow/kitchen/'.$this->entity.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''), $token);
        if ((int) ($response['http_code'] ?? 0) !== 200) {
            dol_syslog(__METHOD__.' entity='.$this->entity.' http='.(int) ($response['http_code'] ?? 0), LOG_WARNING);
            throw new KreaFlowApiException('KreaSynch kitchen is unavailable. Check the private connection and source binding.', 503);
        }
        $data = json_decode($response['content'] ?? '', true);
        if (!is_array($data) || ($data['entity'] ?? null) !== $this->entity || ($data['source_id'] ?? '') !== $source
            || !is_array($data['items'] ?? null) || count($data['items']) > 1000
            || !is_array($data['products'] ?? null) || !is_array($data['counts'] ?? null)
            || !is_array($data['timings'] ?? null) || !isset($data['generated_at'])) {
            throw new KreaFlowApiException('KreaSynch returned an invalid kitchen response.', 502);
        }
        if ($window) {
            if (empty($data['window_start']) || empty($data['window_end'])
                || strtotime($data['window_start']) !== strtotime($window['window_start'])
                || strtotime($data['window_end']) !== strtotime($window['window_end'])) {
                throw new KreaFlowApiException('Kitchen working-day range was not applied.', 502);
            }
            $data['day'] = $window['day'];
        }
        unset($data['source_id']);
        return $data;
    }

    /** Native Dolibarr transport with verified TLS and no credential-bearing redirects. */
    protected function fetch($url, $token)
    {
        require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
        return getURLContent($url, 'GET', '', 0,
            array('Authorization: Bearer '.$token, 'Accept: application/json'), array('https'), 0, 1, 3, 8,
            array(CURLINFO_HEADER_OUT => false, CURLOPT_VERBOSE => false));
    }
}
