<?php

namespace Foolz\FoolFuuka\Controller\Chan;

use Foolz\FoolFrame\Model\Plugins;
use Foolz\FoolFrame\Model\Uri;
use Foolz\FoolFuuka\Model\Board;
use Foolz\FoolFuuka\Model\Comment;
use Foolz\Plugin\Plugin;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Bans extends \Foolz\FoolFuuka\Controller\Chan
{
    /**
     * @var Plugin
     */
    protected $plugin;

    /**
     * @var Uri
     */
    protected $uri;

    public function before()
    {
        $this->plugin = $this->getContext()->getService('plugins')->getPlugin('foolz/foolfuuka-plugin-ban-logging');
        $this->uri = $this->getContext()->getService('uri');
        $this->dc = $this->getContext()->getService('doctrine');

        parent::before();
    }

    public function radix_page($page = 1)
    {
        try {

            $this->builder->getProps()->addTitle(_i('Bans'));
            $this->param_manager->setParam('section_title', _i('Bans'));


            $count = $this->dc->qb()
                ->select('count(`id`) as c')
                ->from($this->dc->p('plugin_fu_ban_logging'))
                ->where('board_id = :board_id')
                ->setParameter(':board_id', $this->radix->id)
                ->execute()
                ->fetchAll();

            $count = $count[0]['c'];

            $per_page = 100;
            $result = $this->dc->qb()
                ->select('*')
                ->from($this->dc->p('plugin_fu_ban_logging'))
                ->orderBy('bantime', 'DESC')
                ->where('board_id = :board_id')
                ->setParameter(':board_id', $this->radix->id)
                ->setMaxResults($per_page)
                ->setFirstResult(($page * $per_page) - $per_page)
                ->execute()
                ->fetchAll();

                $radix = $this->radix;
            ob_start();
            ?>

            <link href="<?= $this->plugin->getAssetManager()->getAssetLink('style.css') ?>" rel="stylesheet"
                  type="text/css"/>

            <?php
            include __DIR__ . '/../../views/Bans.php';

            $string = ob_get_clean();
            $partial = $this->builder->createPartial('body', 'plugin');
            $partial->getParamManager()->setParam('content', $string);

            $this->param_manager->setParams([
                'pagination' => [
                    'base_url' => $this->uri->create([$this->radix->shortname, 'bans', 'page']),
                    'current_page' => $page,
                    'total' => $count/100
                ]
            ]);
        } catch (\Foolz\Foolfuuka\Model\BoardException $e) {
            return $this->error($e->getMessage());
        }

        return new Response($this->builder->build());
    }
}
