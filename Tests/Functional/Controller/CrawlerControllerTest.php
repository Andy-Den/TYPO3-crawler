<?php
namespace AOE\Crawler\Tests\Functional\Controller;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2018 AOE GmbH <dev@aoe.com>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use AOE\Crawler\Controller\CrawlerController;
use AOE\Crawler\Domain\Model\Process;
use AOE\Crawler\Domain\Repository\ProcessRepository;
use AOE\Crawler\Domain\Repository\QueueRepository;
use Doctrine\DBAL\Query\QueryBuilder;
use Nimut\TestingFramework\TestCase\FunctionalTestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * Class CrawlerControllerTest
 *
 * @package AOE\Crawler\Tests\Functional\Controller
 */
class CrawlerControllerTest extends FunctionalTestCase
{
    /**
     * @var array
     */
    protected $coreExtensionsToLoad = ['cms', 'core', 'frontend', 'version', 'lang', 'extensionmanager', 'fluid'];

    /**
     * @var array
     */
    protected $testExtensionsToLoad = ['typo3conf/ext/crawler'];

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    /**
     * @var CrawlerController
     */
    protected $subject;

    public function setUp()
    {
        parent::setUp();
        $this->objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $this->importDataSet(__DIR__ . '/../Fixtures/sys_domain.xml');
        $this->importDataSet(__DIR__ . '/../Fixtures/pages.xml');
        $this->importDataSet(__DIR__ . '/../Fixtures/tx_crawler_configuration.xml');
        $this->importDataSet(__DIR__ . '/../Fixtures/tx_crawler_queue.xml');
        $this->importDataSet(__DIR__ . '/../Fixtures/tx_crawler_process.xml');
        $this->subject = $this->getAccessibleMock(CrawlerController::class, ['dummy']);
    }

    /**
     * @test
     *
     * @param $baseUrl
     * @param $sysDomainUid
     * @param $expected
     *
     * @dataProvider getBaseUrlForConfigurationRecordDataProvider
     */
    public function getBaseUrlForConfigurationRecord($baseUrl, $sysDomainUid, $expected)
    {
        $this->assertEquals(
            $expected,
            $this->subject->_call('getBaseUrlForConfigurationRecord', $baseUrl, $sysDomainUid)
        );
    }

    /**
     * @test
     *
     * @param $uid
     * @param $configurationHash
     * @param $expected
     *
     * @dataProvider noUnprocessedQueueEntriesForPageWithConfigurationHashExistDataProvider
     */
    public function noUnprocessedQueueEntriesForPageWithConfigurationHashExist($uid, $configurationHash, $expected)
    {
        $this->assertEquals(
            $expected,
            $this->subject->_call(
                'noUnprocessedQueueEntriesForPageWithConfigurationHashExist',
                $uid,
                $configurationHash
            )
        );
    }

    /**
     * @test
     */
    public function CLI_deleteProcessesMarkedDeleted()
    {
        $processRepository = $this->objectManager->get(ProcessRepository::class);

        $expectedProcessesBeforeDeletion = 5;
        $this->assertEquals(
            $expectedProcessesBeforeDeletion,
            $processRepository->countAll()
        );

        $this->subject->CLI_deleteProcessesMarkedDeleted();

        $expectedProcessesAfterDeletion = 3;
        $this->assertEquals(
            $expectedProcessesAfterDeletion,
            $processRepository->countAll()
        );
    }

    /**
     * @test
     *
     */
    public function cleanUpOldQueueEntries()
    {
        $this->markTestSkipped('This fails with PHP7 & TYPO3 7.6');

        $this->importDataSet(__DIR__ . '/Fixtures/tx_crawler_queue.xml');
        $queryRepository = new QueueRepository();

        $recordsFromFixture = 9;
        $expectedRemainingRecords = 2;
        // Add records to queue repository to ensure we always have records,
        // that will not be deleted with the cleanUpOldQueueEntries-function
        for ($i = 0; $i < $expectedRemainingRecords; $i++) {
            $this->getDatabaseConnection()->exec_INSERTquery(
                'tx_crawler_queue',
                [
                    'exec_time' => time() + (7 * 24 * 60 * 60),
                    'scheduled' => time() + (7 * 24 * 60 * 60),
                ]
            );
        }

        // Check total entries before cleanup
        $this->assertEquals(
            $recordsFromFixture + $expectedRemainingRecords,
            $queryRepository->countAll()
        );

        $this->subject->_call('cleanUpOldQueueEntries');

        // Check total entries after cleanup
        $this->assertEquals(
            $expectedRemainingRecords,
            $queryRepository->countAll()
        );
    }

    /**
     * @test
     *
     * @param $where
     * @param $expected
     *
     * @dataProvider flushQueueDataProvider
     */
    public function flushQueue($where, $expected)
    {
        $queryRepository = $this->objectManager->get(QueueRepository::class);
        $this->subject->_call('flushQueue', $where);

        $this->assertEquals(
            $expected,
            $queryRepository->countAll()
        );
    }

    /**
     * @test
     *
     * @param $id
     * @param $filter
     * @param $doFlush
     * @param $doFullFlush
     * @param $itemsPerPage
     * @param $expected
     *
     * @dataProvider getLogEntriesForPageIdDataProvider
     */
    public function getLogEntriesForPageId($id, $filter, $doFlush, $doFullFlush, $itemsPerPage, $expected)
    {
        $this->assertEquals(
            $expected,
            $this->subject->getLogEntriesForPageId($id, $filter, $doFlush, $doFullFlush, $itemsPerPage)
        );
    }

    /**
     * @test
     *
     * @param $setId
     * @param $filter
     * @param $doFlush
     * @param $doFullFlush
     * @param $itemsPerPage
     * @param $expected
     *
     * @dataProvider getLogEntriesForSetIdDataProvider
     */
    public function getLogEntriesForSetId($setId, $filter, $doFlush, $doFullFlush, $itemsPerPage, $expected)
    {
        $this->assertEquals(
            $expected,
            $this->subject->getLogEntriesForSetId($setId, $filter, $doFlush, $doFullFlush, $itemsPerPage)
        );
    }

    /**
     * @test
     */
    public function getConfigurationHash()
    {
        $configuration = [
            'paramExpanded' => 'extendedParameter',
            'URLs' => 'URLs',
            'NotImportantParameter' => 'value not important',
        ];

        $originalCheckSum = md5(serialize($configuration));

        $this->assertNotEquals(
            $originalCheckSum,
            $this->subject->_call('getConfigurationHash', $configuration)
        );

        unset($configuration['paramExpanded'], $configuration['URLs']);
        $newCheckSum = md5(serialize($configuration));
        $this->assertEquals(
            $newCheckSum,
            $this->subject->_call('getConfigurationHash', $configuration)
        );
    }

    /**
     * @test
     * @dataProvider isCrawlingProtocolHttpsDataProvider
     */
    public function isCrawlingProtocolHttps($crawlerConfiguration, $pageConfiguration, $expected)
    {
        $this->assertEquals(
            $expected,
            $this->subject->_call(isCrawlingProtocolHttps, $crawlerConfiguration, $pageConfiguration)
        );
    }

    /**
     * @test
     */
    public function CLI_checkAndAcquireNewProcessExpectingSuccessAndReturnTrue()
    {

        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $processRepository = $objectManager->get(ProcessRepository::class);

        // Set the Process Limit to 10, to ensure additional processes can be added.
        $this->subject->setExtensionSettings([
            'processLimit' => 10,
        ]);

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_crawler_process');
        $time = (time() + 40000);
        // Add new process records to have ttl higher than current time
        for ($i = 0; $i < 4; $i++) {
            $queryBuilder
                ->insert('tx_crawler_process')
                ->values([
                    'ttl' => $time,
                    'process_id' => 3000 + $i,
                    'active' => true,
                ])
                ->execute();
        }

        $processesBefore = $processRepository->countActive();

        // Checks that process is returning successfully
        $this->assertTrue($this->subject->CLI_checkAndAcquireNewProcess(102030));
        $processesAfter = $processRepository->countActive();

        // Check that at least one process record is added.
        $this->assertGreaterThan(
            $processesBefore,
            $processesAfter
        );
    }

    /**
     * @test
     */
    public function CLI_checkAndAcquireNewProcessExpectingToReturnFalseBecauseOfProcessLimitExceeded()
    {
        $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        $processRepository = $objectManager->get(ProcessRepository::class);

        // Set the Process Limit to 3, to ensure that to many process are active and new ones cannot be started
        $this->subject->setExtensionSettings([
            'processLimit' => 5,
        ]);

        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tx_crawler_process');
        $time = (time() + 4000);
        // Add new process records to have ttl higher than current time
        for ($i = 0; $i < 5; $i++) {
            $queryBuilder
                ->insert('tx_crawler_process')
                ->values([
                    'ttl' => $time,
                    'process_id' => 4000 + $i,
                    'active' => true,
                ])
                ->execute();
        }

        $id = 1000;
        $processesBefore = $processRepository->countAllByProcessId($id);

        // Checks that process is returning successfully
        $this->assertFalse($this->subject->CLI_checkAndAcquireNewProcess($id));
        $processesAfter = $processRepository->countAllByProcessId($id);

        // Check that no process record is added.
        $this->assertEquals(
            $processesBefore,
            $processesAfter
        );
    }

    /**
     * @test
     */
    public function getConfigurationsForBranch()
    {
        $GLOBALS['BE_USER'] = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->setMethods(['isAdmin'])
            ->getMock();

        $configurationsForBranch = $this->subject->getConfigurationsForBranch(5,99);

        $this->assertNotEmpty($configurationsForBranch);
        $this->assertCount(
            3,
            $configurationsForBranch
        );

        $this->assertEquals(
            $configurationsForBranch,
            [
                'Not hidden or deleted',
                'Not hidden or deleted - uid 5',
                'Not hidden or deleted - uid 6'
            ]
        );
    }

    /**
     * @test
     * @dataProvider getDuplicateRowsIfExistDataProvider
     */
    public function getDuplicateRowsIfExist($timeslotActive, $tstamp, $current, $fieldArray, $expected)
    {

        $mockedCrawlerController = $this->getAccessibleMock(CrawlerController::class, ['getCurrentTime']);
        $mockedCrawlerController->expects($this->any())->method('getCurrentTime')->willReturn($current);

        $mockedCrawlerController->setExtensionSettings([
            'enableTimeslot' => $timeslotActive,
        ]);


        $this->assertEquals(
            $expected,
            $mockedCrawlerController->_call('getDuplicateRowsIfExist', $tstamp, $fieldArray)
        );
    }

    public function getDuplicateRowsIfExistDataProvider()
    {
        return [
            'EnableTimeslot is true and timestamp is <= current' => [
                'timeslotActive' => true,
                'tstamp' => 10,
                'current' => 12,
                'fieldArray' => [
                    'page_id' => 15,
                    'parameters_hash' => ''
                ],
                'expected' => [15,18]
            ],
            'EnableTimeslot is false and timestamp is <= current' => [
                'timeslotActive' => false,
                'tstamp' => 11,
                'current' => 11,
                'fieldArray' => [
                    'page_id' => 15,
                    'parameters_hash' => ''
                ],
                'expected' => [18]
            ],
            'EnableTimeslot is true and timestamp is > current' => [
                'timeslotActive' => true,
                'tstamp' => 12,
                'current' => 10,
                'fieldArray' => [
                    'page_id' => 15,
                    'parameters_hash' => ''
                ],
                'expected' => [15]
            ],
            'EnableTimeslot is false and timestamp is > current' => [
                'timeslotActive' => false,
                'tstamp' => 12,
                'current' => 10,
                'fieldArray' => [
                    'page_id' => 15,
                    'parameters_hash' => ''
                ],
                'expected' => [15]
            ],
            'EnableTimeslot is false and timestamp is > current and parameters_hash is set' => [
                'timeslotActive' => false,
                'tstamp' => 12,
                'current' => 10,
                'fieldArray' => [
                    'page_id' => 15,
                    'parameters_hash' => 'NotReallyAHashButWillDoForTesting'
                ],
                'expected' => [19]
            ],
        ];
    }


    /**
     * @return array
     */
    public function getLogEntriesForSetIdDataProvider()
    {
        return [
            'Do Flush' => [
                'setId' => 456,
                'filter' => '',
                'doFlush' => true,
                'doFullFlush' => false,
                'itemsPerPage' => 5,
                'expected' => [],
            ],
            'Do Full Flush' => [
                'setId' => 456,
                'filter' => '',
                'doFlush' => true,
                'doFullFlush' => true,
                'itemsPerPage' => 5,
                'expected' => [],
            ],
            'Check that doFullFlush do not flush if doFlush is not true' => [
                'setId' => 456,
                'filter' => '',
                'doFlush' => false,
                'doFullFlush' => true,
                'itemsPerPage' => 5,
                'expected' => [[
                    'qid' => '8',
                    'page_id' => '0',
                    'parameters' => '',
                    'parameters_hash' => '',
                    'configuration_hash' => '',
                    'scheduled' => '0',
                    'exec_time' => '0',
                    'set_id' => '456',
                    'result_data' => '',
                    'process_scheduled' => '0',
                    'process_id' => '1007',
                    'process_id_completed' => 'asdfgh',
                    'configuration' => 'ThirdConfiguration',
                ]],
            ],
            'Get entries for set_id 456' => [
                'setId' => 456,
                'filter' => '',
                'doFlush' => false,
                'doFullFlush' => false,
                'itemsPerPage' => 1,
                'expected' => [[
                    'qid' => '8',
                    'page_id' => '0',
                    'parameters' => '',
                    'parameters_hash' => '',
                    'configuration_hash' => '',
                    'scheduled' => '0',
                    'exec_time' => '0',
                    'set_id' => '456',
                    'result_data' => '',
                    'process_scheduled' => '0',
                    'process_id' => '1007',
                    'process_id_completed' => 'asdfgh',
                    'configuration' => 'ThirdConfiguration',
                ]],
            ],
            'Do Flush Pending' => [
                'setId' => 456,
                'filter' => 'pending',
                'doFlush' => true,
                'doFullFlush' => false,
                'itemsPerPage' => 5,
                'expected' => [],
            ],
            'Do Flush Finished' => [
                'setId' => 456,
                'filter' => 'finished',
                'doFlush' => true,
                'doFullFlush' => false,
                'itemsPerPage' => 5,
                'expected' => [],
            ],
        ];
    }

    /**
     * @return array
     */
    public function getLogEntriesForPageIdDataProvider()
    {
        return [
            'Do Flush' => [
                'id' => 1002,
                'filter' => '',
                'doFlush' => true,
                'doFullFlush' => false,
                'itemsPerPage' => 5,
                'expected' => [],
            ],
            'Do Full Flush' => [
                'id' => 1002,
                'filter' => '',
                'doFlush' => true,
                'doFullFlush' => true,
                'itemsPerPage' => 5,
                'expected' => [],
            ],
            'Check that doFullFlush do not flush if doFlush is not true' => [
                'id' => 2001,
                'filter' => '',
                'doFlush' => false,
                'doFullFlush' => true,
                'itemsPerPage' => 5,
                'expected' => [[
                    'qid' => '6',
                    'page_id' => '2001',
                    'parameters' => '',
                    'parameters_hash' => '',
                    'configuration_hash' => '7b6919e533f334550b6f19034dfd2f81',
                    'scheduled' => '0',
                    'exec_time' => '0',
                    'set_id' => '123',
                    'result_data' => '',
                    'process_scheduled' => '0',
                    'process_id' => '1006',
                    'process_id_completed' => 'qwerty',
                    'configuration' => 'SecondConfiguration',
                ]],
            ],
            'Get entries for page_id 2001' => [
                'id' => 2001,
                'filter' => '',
                'doFlush' => false,
                'doFullFlush' => false,
                'itemsPerPage' => 1,
                'expected' => [[
                    'qid' => '6',
                    'page_id' => '2001',
                    'parameters' => '',
                    'parameters_hash' => '',
                    'configuration_hash' => '7b6919e533f334550b6f19034dfd2f81',
                    'scheduled' => '0',
                    'exec_time' => '0',
                    'set_id' => '123',
                    'result_data' => '',
                    'process_scheduled' => '0',
                    'process_id' => '1006',
                    'process_id_completed' => 'qwerty',
                    'configuration' => 'SecondConfiguration',
                ]],
            ],

        ];
    }

    /**
     * @return array
     */
    public function flushQueueDataProvider()
    {
        return [
            'Flush Entire Queue' => [
                'where' => '1=1',
                'expected' => 0,
            ],
            'Flush Queue with specific configuration' => [
                'where' => 'configuration = \'SecondConfiguration\'',
                'expected' => 9,
            ],
            'Flush Queue for specific process id' => [
                'where' => 'process_id = \'1007\'',
                'expected' => 11,
            ],
            'Flush Queue for where that does not exist, nothing is deleted' => [
                'where' => 'qid > 100000',
                'expected' => 14,
            ],
        ];
    }

    /**
     * @return array
     */
    public function getBaseUrlForConfigurationRecordDataProvider()
    {
        return [
            'With existing sys_domain' => [
                'baseUrl' => 'www.baseurl-domain.tld',
                'sysDomainUid' => 1,
                'expected' => 'http://www.domain-one.tld',
            ],
            'Without exting sys_domain' => [
                'baseUrl' => 'www.baseurl-domain.tld',
                'sysDomainUid' => 2000,
                'expected' => 'www.baseurl-domain.tld',
            ],
            'With sys_domain uid with negative value' => [
                'baseUrl' => 'www.baseurl-domain.tld',
                'sysDomainUid' => -1,
                'expected' => 'www.baseurl-domain.tld',
            ],
        ];
    }

    /**
     * @return array
     */
    public function noUnprocessedQueueEntriesForPageWithConfigurationHashExistDataProvider()
    {
        return [
            'No record found, uid not present' => [
                'uid' => 3000,
                'configurationHash' => '7b6919e533f334550b6f19034dfd2f81',
                'expected' => true,
            ],
            'No record found, configurationHash not present' => [
                'uid' => 2001,
                'configurationHash' => 'invalidConfigurationHash',
                'expected' => true,
            ],
            'Record found - uid and configurationHash is present' => [
                'uid' => 2001,
                'configurationHash' => '7b6919e533f334550b6f19034dfd2f81',
                'expected' => false,
            ],
        ];
    }

    /**
     * @return array
     */
    public function isCrawlingProtocolHttpsDataProvider()
    {
        return [
            'Crawler Configuration set to http' => [
                'crawlerConfiguration' => -1,
                'pageConfiguration' => true,
                'expected' => false,
            ],
            'Crawler Configuration set to https' => [
                'crawlerConfiguration' => 1,
                'pageConfiguration' => false,
                'expected' => true,
            ],
            'Crawler Configuration set to page-configuration' => [
                'crawlerConfiguration' => 0,
                'pageConfiguration' => true,
                'expected' => true,
            ],
            'Crawler Configuration default fallback' => [
                'crawlerConfiguration' => 99,
                'pageConfiguration' => true,
                'expected' => false,
            ],
        ];
    }
}
