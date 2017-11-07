<?php

use Doctrine\DBAL\Schema\Schema;
use Foolz\FoolFrame\Model\Autoloader;
use Foolz\FoolFrame\Model\Context;
use Foolz\FoolFrame\Model\Preferences;
use Foolz\FoolFrame\Model\DoctrineConnection;
use Foolz\FoolFrame\Model\Plugins;
use Foolz\FoolFrame\Model\Uri;
use Foolz\FoolFuuka\Model\RadixCollection;
use Foolz\Plugin\Event;
use Symfony\Component\Routing\Route;

class HHVM_FFBanLog
{
    public function run()
    {
        Event::forge('Foolz\Plugin\Plugin::execute#foolz/foolfuuka-plugin-ban-logging')
            ->setCall(function ($plugin) {
                /** @var Context $context */
                $context = $plugin->getParam('context');

                /** @var Autoloader $autoloader */
                $autoloader = $context->getService('autoloader');
                $autoloader->addClassMap([
                    'Foolz\FoolFuuka\Controller\Api\Bans' => __DIR__ . '/classes/controller/api/chan.php',
                    'Foolz\FoolFrame\Controller\Admin\Plugins\Bans' => __DIR__ . '/classes/controller/admin.php',
                    'Foolz\FoolFuuka\Plugins\Bans\Console\Console' => __DIR__ . '/classes/console/console.php',
                    'Foolz\FoolFuuka\Controller\Chan\Bans' => __DIR__ . '/classes/controller/chan.php'
                ]);

                // Routes
                Event::forge('Foolz\FoolFrame\Model\Context::handleWeb#obj.routing')
                    ->setCall(function ($result) use ($context) {
                        // Admin page hook
                        if ($context->getService('auth')->hasAccess('maccess.admin')) {
                            Event::forge('Foolz\FoolFrame\Controller\Admin::before#var.sidebar')
                                ->setCall(function ($result) {
                                    $sidebar = $result->getParam('sidebar');
                                    $sidebar[]['plugins'] = [
                                        "content" => ["bans/manage" => ["level" => "admin", "name" => _i("Ban Logging"), "icon" => 'icon-file']]
                                    ];
                                    $result->setParam('sidebar', $sidebar);
                                });

                            $context->getRouteCollection()->add(
                                'foolfuuka.plugin.bans.admin', new Route(
                                    '/admin/plugins/bans/{_suffix}',
                                    [
                                        '_suffix' => 'manage',
                                        '_controller' => '\Foolz\FoolFrame\Controller\Admin\Plugins\Bans::manage'
                                    ],
                                    [
                                        '_suffix' => '.*'
                                    ]
                                )
                            );
                        }
                        // Get boards for table view
                        $preferences = $context->getService('preferences');

                        if($preferences->get('foolfuuka.plugins.bans.share.enabled')) {
                            $radix_collection = $context->getService('foolfuuka.radix_collection');
                            $radices = $radix_collection->getAll();
                            foreach ($radices as $radix) {
                                $routes = $result->getObject();
                                $routes->getRouteCollection()->add(
                                    'foolfuuka.plugin.bans.chan.radix.' . $radix->shortname, new Route(
                                        '/' . $radix->shortname . '/bans/{_suffix}',
                                        [
                                            '_controller' => '\Foolz\FoolFuuka\Controller\Chan\Bans::*',
                                            '_default_suffix' => 'page',
                                            '_suffix' => '',
                                            'radix_shortname' => $radix->shortname
                                        ],
                                        [
                                            '_suffix' => '.*'
                                        ]
                                    )
                                );
                            }

                            // API view
                            $context->getRouteCollection()->add(
                                'foolfuuka.plugin.bans.chan', new Route(
                                    '/_/api/chan/bans/',
                                    [
                                        '_controller' => 'Foolz\FoolFuuka\Controller\Api\Bans::bans'
                                    ]
                               )
                            );
                        }
                    });

                // Console
                Event::forge('Foolz\FoolFrame\Model\Context::handleConsole#obj.app')
                    ->setCall(function ($result) use ($context) {
                        $result->getParam('application')
                            ->add(new \Foolz\FoolFuuka\Plugins\Bans\Console\Console($context));
                    });

                Event::forge('Foolz\FoolFrame\Model\Context::handleWeb#obj.afterAuth')
                    ->setCall(function ($result) use ($context) {
                        $preferences = $context->getService('preferences');
                        if ($preferences->get('foolfuuka.plugins.bans.share.enabled')) {
                            Event::forge('foolframe.themes.generic_top_nav_buttons')
                                ->setCall(function ($nav) {
                                    $obj = $nav->getObject();
                                    $top = $nav->getParam('nav');
                                    if ($obj->getRadix() && $obj->getRadix()->archive) {
                                        $top[] = ['href' => $obj->getUri()->create([$obj->getRadix()->shortname, 'bans']), 'text' => _i('Bans')];
                                        $nav->setParam('nav', $top)->set($top);
                                    }
                                })->setPriority(1);
                        }
                    });
            });

            Event::forge('Foolz\FoolFrame\Model\Plugin::install#foolz/foolfuuka-plugin-ban-logging')
                ->setCall(function ($result) {
                    /** @var Context $context */
                    $context = $result->getParam('context');
                    /** @var DoctrineConnection $dc */
                    $dc = $context->getService('doctrine');
                    /** @var Schema $schema */
                    $schema = $result->getParam('schema');
                    $table = $schema->createTable($dc->p('plugin_fu_ban_logging'));
                    if ($dc->getConnection()->getDriver()->getName() == 'pdo_mysql') {
                        $table->addOption('charset', 'utf8mb4');
                        $table->addOption('collate', 'utf8mb4_general_ci');
                    }
                    $table->addColumn('id', 'integer', ['unsigned' => true, 'autoincrement' => true]);
                    $table->addColumn('board_id', 'integer', ['unsigned' => true]);
                    $table->addColumn('no', 'integer', ['unsigned' => true]);
                    $table->addColumn('type', 'smallint', ['unsigned' => true]);
                    $table->addColumn('reason', 'string', ['length' => 255]);
                    $table->addColumn('banlength', 'string', ['length' => 255]);
                    $table->addColumn('bantime', 'integer', ['unsigned' => true]);
                    $table->setPrimaryKey(['id']);
                    $table->addUniqueIndex(['board_id', 'no'], $dc->p('plugin_fu_ban_logging_board_id_no_index'));
                    $table->addIndex(['bantime'], $dc->p('plugin_fu_ban_logging_timestamp_index'));
                });
    }
}

(new HHVM_FFBanLog())->run();