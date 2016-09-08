<?php
namespace App;

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

class AvitoService
{
    private $client;
    private $slack;
    private $root;

    public $listUrl = 'https://m.avito.ru/perm/audio_i_video/televizory_i_proektory?user=1';
    public $priceMin = 8000;
    public $priceMax = 25000;

    public function __construct($root, Client $client, \Maknz\Slack\Client $slack)
    {
        $this->root = $root;
        $this->client = $client;
        $this->slack = $slack;
    }

    public function start()
    {
        $crawler = $this->fetchListUrl();
        $items = $this->parseItemsList($crawler);
        if (!$items) {
            throw new \Exception('Emptu items list');
        }

        $newItems = $this->getNewItems($items);

        $this->sendAlerts($newItems);
    }

    public function sendAlerts($data)
    {
        foreach ($data as $item) {

            $message = [];

            $description = $this->getItemDescription($item['link']);

            $message['text'][] = '*' . $item['title'] . '* Цена: ' . number_format($item['price']);
            $message['text'][] = $description['seller'];
            $message['text'][] = $item['link'];
            $message['text'][] = $description['text'];
            $message['text'] = implode("\n", $message['text']);

            if ($description['image']) {
                $message['image_url'] = $description['image'];
            }


            $this->slack->createMessage()
                        ->attach($message)
                        ->send();
        }
    }

    public function getItemDescription($url)
    {
        $crawler = $this->client->request('GET', $url);
        return $this->parseItemContent($crawler);
    }

    public function cacheGet()
    {
        $path = $this->root . '/storage/avito';
        if (!is_file($path)) {
            return [];
        }
        $data = file_get_contents($path);
        return $data ? json_decode($data, true) : [];
    }

    public function cacheSet($data)
    {
        file_put_contents($this->root . '/storage/avito', json_encode($data));
    }

    public function getNewItems($items)
    {

        $cachedItems = $this->cacheGet();
        $newItemsIds = array_diff(array_keys($items), (array)$cachedItems);
        $this->cacheSet(array_keys($items));

        $newItems = [];
        foreach ($newItemsIds as $itemId) {
            if (!($this->priceMin <= $items[$itemId]['price'] && $items[$itemId]['price'] <= $this->priceMax)) {
                continue;
            }
            $newItems[$itemId] = $items[$itemId];
        }

        return $newItems;
    }

    public function fetchListUrl()
    {
        return $this->client->request('GET', $this->listUrl);
    }

    public function parseItemsList(Crawler $crawler)
    {
        $crawler->filterXPath('//section/article[@data-item-premium="0"]')->each(function ($crawler) use (&$data) {
            $element = $this->parseItemsElement($crawler);
            $data[$element['id']] = $element;
        });

        return $data;
    }

    private function parseItemsElement(Crawler $crawler)
    {
        $price = $crawler->filterXPath('//div[@class="item-price "]');
        if (!$price->count()) {
            $price = $crawler->filterXPath('//div[@class="item-price price-discount"]');
        }

        $id = $crawler->attr('data-item-id');
        $title = $crawler->filterXPath('//span[@class="header-text"]')->text();
        $price = $price->count() ? preg_replace('#[\D]*#', '', $price->text()) : '';
        $link = 'https://m.avito.ru' . $crawler->filterXPath('//a[@class="item-link"]')->attr('href');

        return compact('id', 'title', 'price', 'link');
    }

    public function parseItemContent(Crawler $crawler)
    {
        $text = trim($crawler->filterXPath('//div[@id="description"]/div/div')->html());
        $text = str_replace(['<br>', '<p>', '</p>'], "\n", $text);
        $text = preg_replace("#\n+#", "\n", $text);

        $seller = trim($crawler->filterXPath('//div[contains(@class,"person-name")]')->text());
        $seller = str_replace("\n", ' ', $seller);

        try {
            $image = $crawler->filterXPath('//link[@rel="image_src"]')->attr('href');
        } catch (\Exception $e) {
            $image = null;
        }

        return compact('seller', 'text', 'image');
    }
}