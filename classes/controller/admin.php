<?php

namespace Foolz\FoolFrame\Controller\Admin\Plugins;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Foolz\FoolFrame\Model\Validation\ActiveConstraint\Trim;
use Symfony\Component\Validator\Constraints as Assert;

class Bans extends \Foolz\FoolFrame\Controller\Admin
{
    public function before()
    {
        parent::before();

        $this->param_manager->setParam('controller_title', _i('Plugins'));
    }

    public function security()
    {
        return $this->getAuth()->hasAccess('maccess.admin');
    }

    protected function structure()
    {
        $arr = [
            'open' => [
                'type' => 'open',
            ],
            'foolfuuka.plugins.bans.share.enabled' => [
                'preferences' => true,
                'type' => 'checkbox',
                'label' => '',
                'help' => _i('Enable "Bans" tab on boards list ?')
            ],
            'foolfuuka.plugins.bans.get.enabled' => [
                'preferences' => true,
                'type' => 'checkbox',
                'label' => '',
                'help' => _i('Enable new ban fetching?')
            ],
            'separator' => [
                'type' => 'separator-short',
            ],
            'foolfuuka.plugins.bans.get.sleep' => [
                'database' => true,
                'label' => _i('Daemon sleep time between synchronizing (in minutes).'),
                'type' => 'input',
                'class' => 'span1',
                'validation' => [new Assert\NotBlank(), new Assert\Type('digit')],
            ],
            'separator1' => [
                'type' => 'separator-short',
            ],
            'paragraph' => [
                'type' => 'paragraph',
                'help' => _i('Run the daemon from the FoolFuuka directory:<br><pre>php console bans:run</pre>')
            ],
            'separator2' => [
                'type' => 'separator-short',
            ],
            'submit' => [
                'type' => 'submit',
                'class' => 'btn-primary',
                'value' => _i('Submit')
            ],
            'close' => [
                'type' => 'close'
            ],
        ];

        return $arr;
    }

    public function action_manage()
    {
        $this->param_manager->setParam('method_title', [_i('FoolFuuka'), _i("Bans Logging"),_i('Manage')]);

        $data['form'] = $this->structure();

        $this->preferences->submit_auto($this->getRequest(), $data['form'], $this->getPost());

        // create a form
        $this->builder->createPartial('body', 'form_creator')
            ->getParamManager()->setParams($data);

        return new Response($this->builder->build());
    }
}
