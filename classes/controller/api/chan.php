<?php

namespace Foolz\FoolFuuka\Controller\Api;

use Foolz\FoolFrame\Model\Context;
use Foolz\FoolFrame\Model\DoctrineConnection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

class Bans extends \Foolz\FoolFuuka\Controller\Api\Chan
{
    /**
     * @var DoctrineConnection
     */
    protected $dc;

    /**
     * @var context
     */
    protected $context;

    public function before()
    {
        $this->context = $this->getContext();
        $this->dc = $this->getContext()->getService('doctrine');

        parent::before();
    }

    public static function isValidPostNumber($str)
    {
        return ctype_digit((string) $str);
    }

    public function get_bans($page = 1)
    {
        $this->response = new JsonResponse();
        if ($this->request->get('page')) {
            $page = $this->request->get('page');
            if (!$this->isValidPostNumber($page)) {
                return $this->response->setData(['error' => _i('The value for "page" is invalid.')])->setStatusCode(422);
            }
        }

        if (!$this->check_board()) {
            return $this->response->setData(['error' => _i('No board selected.')])->setStatusCode(422);
        }

        $count = $this->dc->qb()
            ->select('count(`id`) as c')
            ->from($this->dc->p('plugin_fu_ban_logging'))
            ->where('board_id = :board_id')
            ->setParameter(':board_id', $this->radix->id)
            ->execute()
            ->fetchAll();

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

        $results = [];

        foreach($result as $r) {
            unset($r['id']);
            unset($r['board_id']);
            array_push($results, $r);
        }

        $this->response->setData(['bans' => $results,'total_count' => $count[0]['c']]);

        return $this->response;
    }
}
