<?php

/**
 * @file plugins/generic/saoVocab/SaoVocabPlugin.php
 *
 * Copyright (c) 2024 Mä ja sä
 * Distributed under the GNU GPL v3. For full terms see LICENSE or https://www.gnu.org/licenses/gpl-3.0.txt
 *
 * @class SaoVocab
 * @ingroup plugins_generic_saoVocab
 *
 */

namespace APP\plugins\generic\saoVocab;

use GuzzleHttp\Client;
use GuzzleHttp\Promise;

use APP\core\Application;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\submission\SubmissionKeywordDAO;

class SaoVocabPlugin extends GenericPlugin
{

    /**
     * Constants
     */

    const ALLOWED_VOCABS_AND_LANGS = [
        SubmissionKeywordDAO::CONTROLLED_VOCAB_SUBMISSION_KEYWORD => ['sv'],
    ];


    /**
     * Public 
     */

    // GenericPlugin methods

    public function register($category, $path, $mainContextId = null)
    {
        if (Application::isUnderMaintenance()) {
            return true;
        }
        $success = parent::register($category, $path);
        if ($success && $this->getEnabled()) {
            // Add hook
            Hook::add('API::vocabs::external', $this->setData(...));
        }
        return $success;
    }

    public function getActions($request, $actionArgs)
    {
        return parent::getActions($request, $actionArgs);
    }

    public function getDisplayName()
    {
        return __('plugins.generic.saoVocab.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.saoVocab.description');
    }

    public function getCanEnable()
    {
        return !!Application::get()->getRequest()->getContext();
    }

    public function getCanDisable()
    {
        return !!Application::get()->getRequest()->getContext();
    }

    public function manage($args, $request)
    {
        return parent::manage($args, $request);
    }

    // Own methods

    /**
     * Public
     */

    public function setData(string $hookName, array $args): bool
    {
        $vocab = $args[0];
        $term = $args[1];
        $locale = $args[2];
        $data = &$args[3];
        $entries = &$args[4];
        $illuminateRequest = $args[5];
        $response = $args[6];
        $request = $args[7];

        if (!isset(self::ALLOWED_VOCABS_AND_LANGS[$vocab]) || !in_array($locale, self::ALLOWED_VOCABS_AND_LANGS[$vocab])) {
            return false;
        }

        $resultData = $this->fetchData($term, $locale);

        if (!$resultData) {
            $data = [];
            return false;
        }

        $data = $resultData;

        return false;
    }

    /**
     * Private
     */

    private function fetchData(?string $term, string $locale): array
    {
        $termSanitized = $term ?? "";

        if (strlen($termSanitized) < 3) {
            return [];
        }

        $client = new Client();
        $promises = [
            'sao' => $client->requestAsync(
                'GET',
                "https://id.kb.se/find",
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-type' => 'application/json'
                    ],
                    'timeout'  => 3.13,
                    'query' => [
                        'o' => "https://id.kb.se/term/sao",
                        'q' => "$termSanitized*",
                        '_limit' => 20,
                    ],
                ]
            ),
        ];
        $responses = Promise\Utils::settle($promises)->wait();

        return collect($responses)
            ->reduce(function (array $data, array $response, string $service): array {
                if (isset($response['value']) && $response['value']->getStatusCode() === 200) {
                    array_push($data, ...$this->{$service}(json_decode($response['value']->getBody()->getContents(), true)));
                }
                return $data;
            }, []);
    }

    /**
     * Functions for vocabularies
     */

    private function sao(?array $responseContents)
    {
        return collect($responseContents['items'] ?? [])
            ->unique('@id')
            ->filter()
            ->map(fn (array $d): array =>
                [
                    'term' => $d['prefLabel'],
                    'label' => "{$d['prefLabel']} [ {$d['@id']} ]",
                    'uri' => $d['@id'],
                    'service' => 'SAO',
                ])
            ->values()
            ->toArray();
    }
}
