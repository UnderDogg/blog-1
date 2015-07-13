<?php
/*
 +------------------------------------------------------------------------+
 | Kitsune                                                                |
 +------------------------------------------------------------------------+
 | Copyright (c) 2015-2015 Phalcon Team and contributors                  |
 +------------------------------------------------------------------------+
 | This source file is subject to the New BSD License that is bundled     |
 | with this package in the file docs/LICENSE.txt.                        |
 |                                                                        |
 | If you did not receive a copy of the license and are unable to         |
 | obtain it through the world-wide-web, please send an email             |
 | to license@phalconphp.com so we can send you a copy immediately.       |
 +------------------------------------------------------------------------+
*/

/**
 * PostFinder.php
 * \Kitsune\PostFinder
 *
 * Allows faster searching of blog posts
 */
namespace Kitsune;

use Phalcon\Di\Injectable as PhDiInjectable;
use Kitsune\Exceptions\Exception as KException;

class PostFinder extends PhDiInjectable
{
    private $data       = [];
    private $tags       = [];
    private $links      = [];
    private $linkNumber = [];
    private $dates      = [];

    /**
     * The constructor. Reads the posts JSON file and creates the necessary
     * mapping indexes
     *
     * @throws KException
     */
    public function __construct()
    {
        $sourceFile = K_PATH . '/data/posts.json';

        if (!file_exists($sourceFile)) {
            throw new KException('Posts JSON file cannot be located');
        }

        $contents = file_get_contents($sourceFile);
        $data     = json_decode($contents, true);
        $dates    = [];

        if (false === $data) {
            throw new KException('Posts JSON file is potentially corrupted');
        }

        /**
         * First all the data will go in a master array
         */
        foreach ($data as $item) {
            $post = new Post($item);

            /**
             * Add the element in the master array
             */
            $this->data[$post->getSlug()] = $post;

            /**
             * Tags
             */
            foreach ($post->getTags() as $tag) {
                $this->tags[trim($tag)][] = $post->getSlug();
            }

            /**
             * Links
             */
            $this->links[$post->getLink()] = $post->getSlug();

            /**
             * Check if the link is a tumblr one and get its number
             */
            $position = strpos($post->getLink(), '/');
            if (false !== $position) {
                $linkNumber = substr($post->getLink(), 0, $position);
                $this->linkNumber[$linkNumber] = $post->getSlug();
            }

            /**
             * Dates (sorting)
             */
            $dates[$post->getDate()] = $post->getSlug();
        }

        /**
         * Sort the dates array
         */
        krsort($dates);
        $postsPerPage = intval($this->config->blog->postsPerPage);
        $postsPerPage = ($postsPerPage < 0) ? 10 : $postsPerPage;
        $this->dates  = array_chunk($dates, $postsPerPage);

        /**
         * Adding one element to the beginning of the array to deal with the
         * 0 based array index since the array keys correspond to the pages
         */
        array_unshift($this->dates, []);
    }

    /**
     * Gets the latest number of posts for the blog. Used in the first page
     *
     * @param  int $page   The page
     *
     * @return array
     */
    public function getLatest($page = 1)
    {
        $key   = sprintf('posts-latest-%s.cache', $page);
        $posts = $this->utils->cacheGet($key);

        if ($posts === null) {
            $page = ($page < 1) ? 1 : $page;
            $dates = $this->utils->fetch($this->dates, $page, null);
            vd($page);
            vdd($dates);
            if (!is_null($dates)) {
                foreach ($dates as $date) {
                    $posts[] = $this->data[$date];
                }
                $this->cache->save($key, $posts);
            } else {
                $this->response->redirect('', false, 301);
            }
        }

        return $posts;
    }

    /**
     * Gets a post from the internal collection based on a slug. If the slug is
     * numeric, this is a Disqus link. The function will find it and return the
     * correct post.
     *
     * @param  string $slug The slug of the post
     *
     * @return mixed
     */
    public function get($slug)
    {
        if (is_numeric($slug)) {
            if (array_key_exists($slug, $this->linkNumber)) {
                $slug = $this->linkNumber[$slug];
                $this->response->redirect('/post/' . $slug, false, 301);
            }
        }

        $key  = 'post-' . $slug . '.cache';
        $post = $this->utils->cacheGet($key);

        if ($post === null) {
            if (array_key_exists($slug, $this->data)) {
                $post = $this->data[$slug];
                $this->cache->save($key, $post);
            }
        }

        return $post;
    }

    public function getPages($page = 1)
    {
        $return = [
            'previous' => $page - 1,
            'next'     => $page + 1
        ];

        $totalPages = count($this->dates);

        if (($page + 1) > $totalPages) {
            $return['next'] = 0;
        }

        if (($page - 1) < 1) {
            $return['previous'] = 0;
        }

        return $return;
    }
}
