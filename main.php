<?php

/**
 * PHP Version >= PHP 7.4
 *
 * @category Migration
 * @package  OtoediMigration
 * @author   Serkan Mert Kaptan <serkan.kaptan@map.com.tr>
 * @license  MAP E-Commerce and Data Services Inc.
 * @link     https://www.map.com.tr
 * @see      http://jira.map.com.tr:8090/browse/OT-157
 */

namespace Otoedi\Migration;

require_once __DIR__ . '/vendor/autoload.php';

use Exception;
use Laminas\Db\Adapter\Exception\InvalidQueryException;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Otoedi\Migration\Helper\Db;
use Monolog\Logger;
use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Monolog\Handler\StreamHandler;

try {
    $logger = new Logger('o3Mig');
    $streamHandler = new StreamHandler('php://stdout', Logger::DEBUG);
    $formatter = new ColoredLineFormatter(null, null, 'Y-m-d H:i:s', false, true);
    $streamHandler->setFormatter($formatter);
    $logger->pushHandler($streamHandler);
} catch (Exception $e) {
    die("Log configuration is invalid : {$e->getMessage()}" . PHP_EOL);
}

echo "Please type source party code to be migrated: ";
$otoediCode = trim(fgets(STDIN));
// ---------------------------------------------------------------------------------------------------------------------
$dbConfig = json_decode(file_get_contents(__DIR__ . "/config/db.config.json"));
!empty($dbConfig) or die('Can not be found the configuration parameters!');
$instanceS = new Db((array)$dbConfig->source);
$instanceT = new Db((array)$dbConfig->target);
// ---------------------------------------------------------------------------------------------------------------------
$partyWhere = new Where();
$partyWhere->nest()
    ->equalTo("PS.EDI_CODE", $otoediCode)
    ->or
    ->equalTo("PR.EDI_CODE", $otoediCode)
    ->unnest();
$relations = $instanceS->get(
    "PARTIES_PARTIES",
    "PP",
    [],
    $partyWhere,
    false,
    "PP.XDOC_TYPE_ID",
    [
        ["name" => ["PS" => "PARTIES"], "on" => "PS.ID = PP.SENDER_PARTIES_ID", "columns" => ["senderName" => "NAME", "senderCode" => "EDI_CODE", "senderId" => "ID"], "type" => Select::JOIN_LEFT],
        ["name" => ["PR" => "PARTIES"], "on" => "PR.ID = PP.RECEPIENT_PARTIES_ID", "columns" => ["receiverName" => "NAME", "receiverCode" => "EDI_CODE", "receiverId" => "ID"], "type" => Select::JOIN_LEFT],
    ]
);
$logger->info(count($relations) . " relations found.");
$connectionT = $instanceT->adapter->getDriver()->getConnection();
$connectionT = $connectionT->beginTransaction();

$relationCounter = 1;
$totalRelation = count($relations);
foreach ($relations as $relation) {
    switch ($relation["XDOC_TYPE_ID"]) {
        case "1":
        case "2":
            $relation["supplierCode"] = $relation["receiverCode"];
            $relation["supplierName"] = $relation["receiverName"];
            $relation["supplierId"] = $relation["receiverId"];
            $relation["buyerCode"] = $relation["senderCode"];
            $relation["buyerName"] = $relation["senderName"];
            $relation["buyerId"] = $relation["senderId"];
            break;
        case "3":
            $relation["supplierCode"] = $relation["senderCode"];
            $relation["supplierName"] = $relation["senderName"];
            $relation["supplierId"] = $relation["senderId"];
            $relation["buyerCode"] = $relation["receiverCode"];
            $relation["buyerName"] = $relation["receiverName"];
            $relation["buyerId"] = $relation["receiverId"];
            break;
    }
    $supplierInformation = $instanceT->get("party", "p", [], ["otoedi_code" => $relation["supplierCode"], "type" => 2]);
    if (empty($supplierInformation)) {
        $logger->warning("Supplier {$relation["supplierCode"]}, {$relation["supplierName"]} doesn't exist at target database. Creating.. ");
        $supplierId = $instanceT->create(
            "party",
            [
                "identifier" => $relation["supplierCode"],
                "otoedi_code" => $relation["supplierCode"],
                "type" => 2,
                "name" => mb_substr($relation["supplierName"], 0, 50),
                "insert_date" => date("Y-m-d H:i:s", time())
            ]
        );
        $supplierInformation["party_id"] = $supplierId;
        $logger->debug("Supplier party created with following row id, $supplierId.");
    }
    $supplierInformation["migratedID"] = $relation["supplierId"];
    $buyerInformation = $instanceT->get("party", "p", [], ["otoedi_code" => $relation["buyerCode"], "type" => 1]);
    if (empty($buyerInformation)) {
        $logger->warning("Buyer {$relation["buyerCode"]}, {$relation["buyerName"]} doesn't exist at target database. Creating.");
        $buyerPartyId = $instanceT->create(
            "party",
            [
                "identifier" => $relation["buyerCode"],
                "otoedi_code" => $relation["buyerCode"],
                "type" => 1,
                "name" => mb_substr($relation["buyerName"], 0, 50),
                "insert_date" => date("Y-m-d H:i:s", time())
            ]
        );
        $buyerInformation["party_id"] = $buyerPartyId;
        // $logger->debug("Related buyer party created with following row id, $buyerPartyId.");
    }
    $buyerInformation["migratedID"] = $relation["buyerId"];
    $sellerInformation = $instanceT->get("party", "p", [], ["otoedi_code" => $relation["supplierCode"]]);
    if (empty($sellerInformation)) {
        $logger->warning("Seller  {$relation["supplierCode"]} doesn't exist at target database. Creating.");
        $sellerPartyId = $instanceT->create(
            "party",
            [
                "identifier" => $relation["supplierCode"],
                "otoedi_code" => $relation["supplierCode"],
                "type" => 4,
                "name" => $relation["NAME"],
                "insert_date" => date("Y-m-d H:i:s", time())
            ]
        );
        $sellerInformation["party_id"] = $sellerPartyId;
        // $logger->debug("Related seller party created with following row id, $sellerPartyId.");
    }
    $pr = $instanceT->get(
        "party_relation",
        "pr",
        [],
        [
            "fk_buyer_id" => $buyerInformation["party_id"],
            "fk_supplier_id" => $supplierInformation["party_id"]
        ]
    );
    if (empty($pr)) {
        $prId = $instanceT->create(
            "party_relation",
            [
                "fk_buyer_id" => $buyerInformation["party_id"],
                "fk_supplier_id" => $supplierInformation["party_id"]
            ]
        );
        $pr["pr_id"] = $prId;
        // $logger->debug("Party relation created with row id, $prId.");
    }
    $logger->notice("Working with relation {$pr["pr_id"]} ({$relation["buyerName"]}:{$relation["supplierName"]}) and document type id {$relation["XDOC_TYPE_ID"]}");
    $logger->info("Relation progress: " . round($relationCounter / $totalRelation * 100) . "%");
    $relationCounter++;
    // ---------------------------------------------------------------------------------------------------------
    $xdocWhere = new Where();
    $xdocWhere->equalTo("X.SENDER_PARTY_ID", $relation["senderId"])
        ->equalTo("X.RECEPIENT_PARTY_ID", $relation["receiverId"])
        ->equalTo("X.XDOC_TYPE_ID", $relation["XDOC_TYPE_ID"])
        ->lessThan("X.STATUS", "32");
    $getXdocList = $instanceS->get(
        "XDOC",
        "X",
        [],
        $xdocWhere,
        false,
        "X.XDOC_TYPE_ID",
        [
            ["name" => ["XT" => "XDOC_TYPE"], "on" => "XT.ID = X.XDOC_TYPE_ID", "columns" => ["TYPE"], "type" => Select::JOIN_LEFT]
        ],
        "X.ID"
    );
    if (empty($getXdocList)) {
        $logger->warning("No document. Skipping.");
        continue;
    }
    $logger->info(count($getXdocList) . " documents found.");
    if (!isset($getXdocList[0])) {
        $getXdocList = [$getXdocList];
    }
    $xdocTotal = count($getXdocList);
    $xdocCounter = 1;
    $percentDiff = 0;
    foreach ($getXdocList as $i => $xdoc) {
        $percent =  round($xdocCounter / $xdocTotal * 100);
        if ($i == 0) {
            $percentDiff = $percent;
            $logger->info("XDOC progress: " . $percent . "% - " . "($xdocCounter/$xdocTotal)");
        }
        if ($percent != $percentDiff) {
            $percentDiff = $percent;
            $logger->info("XDOC progress: " . $percent . "% - " . "($xdocCounter/$xdocTotal)");
        }
        $xdocCounter++;
        if (empty($xdoc["TYPE"])) {
            $logger->critical("Cannot identify document type, xdoc id: {$xdoc["ID"]}. Aborted.");
            $connectionT->rollback();
            continue;
        }
        try {
            $releaseNumberSuffix = $xdoc["REPLACEMENT_XDOC_ID"] > 0 ? ("-" . $xdoc["REPLACEMENT_XDOC_ID"]) : null;
            $migrationPath = "/var/www/data/000_backendApps/v3.otoedi.com/40/";
            $xmlPath = substr($xdoc["XML_PATH"], 34, strlen($xdoc["XML_PATH"]));

            $documentId = $instanceT->create(
                "document",
                [
                    "fk_pr_id" => $pr["pr_id"],
                    "fk_dt_id" => in_array($xdoc["XDOC_TYPE_ID"], [1, 2]) ? 1 : 2,
                    "type" => $xdoc["TYPE"],
                    "number" => $xdoc["RELEASE_NUMBER"] . $releaseNumberSuffix,
                    "control_reference" => $xdoc["RELEASE_NUMBER"],
                    "datetime" => $xdoc["ISSUE_DATE"],
                    "additional_information" => '{"migratedFromV2": "yes","validityPeriod": {"from": "", "until": ""}, "sender_edi_code": "'
                        . $buyerInformation["party_id"] . '", "receiver_edi_code": "'
                        . $supplierInformation["party_id"] . '"}',
                    "current_path" => $migrationPath . $xmlPath,
                    "insert_date" => $xdoc["INSERT_TIME"],
                ]
            );

            // $logger->debug("Document created, document id: $documentId, xdoc id: {$xdoc["ID"]}");
        } catch (InvalidQueryException $e) {
            $logger->critical("Cannot create document, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
            die();
        }
        switch ($xdoc["TYPE"]) {
            case "DELFOR":
                $delforList = $instanceS->get(
                    "XDOC_DELFOR_DETAIL",
                    "XDD",
                    [],
                    ["XDD.XDOC_ID" => $xdoc["ID"]],
                    false,
                    false,
                    [
                        ["name" => ["XD" => "XDOC_DELFOR"], "on" => "XDD.DELFOR_ID = XD.ID", "columns" => ["Snrf", "BeginningInventoryDate", "HorizonEndDate", "BuyerCode", "SupplierCode", "SellerCode"], "type" => Select::JOIN_LEFT],
                        ["name" => ["X" => "XDOC"], "on" => "XD.XDOC_ID = X.ID", "columns" => ["ISSUE_DATE",], "type" => Select::JOIN_LEFT],
                        ["name" => ["DD" => "DESADV_DELJIT"], "on" => "XDD.ID = DD.XDOC_DELFOR_DETAIL_ID", "columns" => ["XDOC_DESADV_DETAIL_ID"], "type" => Select::JOIN_LEFT],
                        ["name" => ["XDESD" => "XDOC_DESADV_DETAIL"], "on" => "XDESD.ID = DD.XDOC_DESADV_DETAIL_ID", "columns" => [], "type" => Select::JOIN_LEFT],
                        ["name" => ["XDESDX" => "XDOC"], "on" => "XDESD.XDOC_ID = XDESDX.ID", "columns" => ["DesadvStatus" => "STATUS"], "type" => Select::JOIN_LEFT],
                    ]
                );
                if (!isset($delforList[0])) {
                    $delforList = [$delforList];
                }
                try {
                    $orderId = $instanceT->create(
                        "order",
                        [
                            "fk_document_id" => $documentId,
                            "fk_pr_id" => $pr["pr_id"],
                            "order_number" => !empty($delforList[0]["Snrf"]) ? $delforList[0]["Snrf"] : $xdoc["RELEASE_NUMBER"],
                            "order_date" => $xdoc["ISSUE_DATE"],
                            "horizon_start_date" => $delforList[0]["BeginningInventoryDate"] == "0000-00-00" ? null : @$delforList[0]["BeginningInventoryDate"],
                            "horizon_end_date" => $delforList[0]["HorizonEndDate"] == "0000-00-00" ? null : @$delforList[0]["HorizonEndDate"],
                            "fk_buyer_id" => $buyerInformation["party_id"],
                            "buyer_identifier" => @$delforList[0]["BuyerCode"],
                            "fk_supplier_id" => $supplierInformation["party_id"],
                            "supplier_identifier" => @$delforList[0]["SupplierCode"],
                            "fk_seller_id" => $supplierInformation["party_id"],
                            "seller_identifier" => @$delforList[0]["SellerCode"],
                            "insert_date" => $xdoc["INSERT_TIME"],
                            "is_confirmed" => 1
                        ]
                    );
                    //$logger->debug("Order created, order id: $orderId.");
                } catch (InvalidQueryException $e) {
                    $logger->critical("Cannot create Order, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                    $connectionT->rollback();
                    die();
                }
                $isCompleted = 1;
                foreach ($delforList as $item) {
                    if ($item["ForecastNetQuantity"] != $item["ForecastDeliveredQuantity"]) {
                        $isCompleted = 0;
                        break;
                    }
                }
                foreach ($delforList as $i => $line) {
                    $consignee = $instanceT->get("consignee", "c", [], ["identifier" => str_replace("&amp;", "&", $line["DeliveryPointCode"]), "fk_buyer_id" => $buyerInformation["party_id"]]);
                    if (empty($consignee)) {
                        try {
                            $consigneeId = $instanceT->create(
                                "consignee",
                                [
                                    "fk_buyer_id" => $buyerInformation["party_id"],
                                    "identifier" => str_replace("&amp;", "&", $line["DeliveryPointCode"]),
                                    "name" => str_replace("&amp;", "&", $line["DeliveryPointCode"]),
                                ]
                            );
                            $consignee["consignee_id"] = $consigneeId;

                            // $logger->debug("Consignee created, consignee id: $consigneeId.");
                        } catch (InvalidQueryException $e) {
                            $logger->critical("Cannot create consignee, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                            $connectionT->rollback();
                            die();
                        }
                    }
                    if (!empty($line["UnloadingDockCode"])) {
                        $dock = $instanceT->get("dock", "d", [], ["identifier" => str_replace("&amp;", "&", $line["UnloadingDockCode"]), "fk_buyer_id" => $buyerInformation["party_id"]]);
                        if (empty($dock) and !empty($line["UnloadingDockCode"]) and strlen($line["UnloadingDockCode"]) > 0) {
                            try {
                                $dockId = $instanceT->create(
                                    "dock",
                                    [
                                        "fk_buyer_id" => $buyerInformation["party_id"],
                                        "fk_consignee_id" => $consignee["consignee_id"],
                                        "identifier" => str_replace("&amp;", "&", $line["UnloadingDockCode"]),
                                        "name" => str_replace("&amp;", "&", $line["UnloadingDockCode"]),
                                    ]
                                );
                                $dock["dock_id"] = $dockId;

                                //$logger->debug("Dock created, dock id: $dockId.");
                            } catch (InvalidQueryException $e) {
                                $logger->critical("Cannot create dock, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                                $connectionT->rollback();
                                die();
                            }
                        }
                    }
                    $orderConsignee = $instanceT->get(
                        "order_consignee",
                        "oc",
                        [],
                        [
                            "fk_order_id" => $orderId,
                            "fk_consignee_id" => $consignee["consignee_id"],
                            "fk_dock_id" => $dock["dock_id"] ?? null,
                            "consignee_identifier" => $line["DeliveryPointCode"],
                            "dock_identifier" => !empty($line["UnloadingDockCode"]) ? $line["UnloadingDockCode"] : $line["DeliveryPointCode"],
                            "is_replaced" => $xdoc["REPLACEMENT_XDOC_ID"] > 0 ? 1 : 0,
                        ]
                    );
                    if (empty($orderConsignee)) {
                        try {
                            $ocId = $instanceT->create(
                                "order_consignee",
                                [
                                    "fk_order_id" => $orderId,
                                    "fk_consignee_id" => $consignee["consignee_id"],
                                    "fk_dock_id" => $dock["dock_id"] ?? null,
                                    "consignee_identifier" => $line["DeliveryPointCode"],
                                    "dock_identifier" => !empty($line["UnloadingDockCode"]) ? $line["UnloadingDockCode"] : $line["DeliveryPointCode"],
                                "is_completed" => $isCompleted,
                                    "type" => "forecast"
                                ]
                            );
                            $orderConsignee["order_consignee_id"] = $ocId;

                            // $logger->debug("OC created, oc id: $ocId.");
                        } catch (InvalidQueryException $e) {
                            $logger->critical("Cannot create oc, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                            $connectionT->rollback();
                            die();
                        }
                    }
                    $product = $instanceT->get("product", "p", [], ["identifier" => $line["ItemSenderCode"], "fk_supplier_id" => $supplierInformation["party_id"]]);
                    if (empty($product)) {
                        try {
                            $productId = $instanceT->create(
                                "product",
                                [
                                    "fk_supplier_id" => $supplierInformation["party_id"],
                                    "identifier" => trim($line["ItemSenderCode"]),
                                    "description" => !empty($line["ItemDescription"]) ? $line["ItemDescription"] : $line["ItemSenderCode"]
                                ]
                            );
                            $product["product_id"] = $productId;
                            //  $logger->debug("Product created, id: $productId.");
                        } catch (InvalidQueryException $e) {
                            $logger->critical("Cannot create product, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                            $connectionT->rollback();
                            die();
                        }
                    }
                    try {
                        $orderDetailId = $instanceT->create(
                            "order_line",
                            [
                                "fk_order_consignee_id" => $orderConsignee["order_consignee_id"],
                                "fk_order_id" => $orderId,
                                "fk_product_id" => $product["product_id"],
                                "line_number" => $line["SchedulingConditionId"],
                                "release_number" => 0,
                                "identifier" => !empty($line["ItemSenderCode"]) ? $line["ItemSenderCode"] : $line["ItemReceiverCode"],
                                "description" => $line["ItemDescription"] ?? null,
                                "buyer_code" => $line["ItemSenderCode"],
                                "supplier_code" => $line["ItemReceiverCode"] ?? null,
                                "delivery_call_number" => null,
                                "contract_number" => null,
                                "earliest_datetime" => $line["ForecastPeriodStartDate"] == "0000-00-00" ? null : $line["ForecastPeriodStartDate"],
                                "latest_datetime" => $line["HorizonEndDate"] == "0000-00-00" ? null : $line["HorizonEndDate"],
                                "collection_datetime_earliest" => null,
                                "collection_datetime_latest" => null,
                                "last_despatch_datetime" => $line["LastAsnShipmentDate"] == "0000-00-00" ? null : $line["LastAsnShipmentDate"],
                                "order_status" => "forecast",
                                "quantity_original" => $line["ForecastNetQuantity"],
                                "quantity_confirmed" => $line["ForecastNetQuantity"], // !empty($line["approveduserid"]) ? $line["ForecastNetQuantity"] : 0,
                                "quantity_packing" => 0,
                                "quantity_packed" => 0,
                                "quantity_to_be_shipped" => 0,
                                "quantity_shipped" => $line["ForecastDeliveredQuantity"],
                                "quantity_invoiced" => 0,
                                "unit_quantity" => $line["ForecastNetQuantityUom"],
                                "unit_price" => 0,
                                "unit_price_basis" => null,
                                "line_amount" => null,
                                "unit_price_currency" => null,
                                "original_delivery_date" => $line["ForecastPeriodStartDate"] == "0000-00-00" ? null : $line["ForecastPeriodStartDate"],
                                "additional_information" => null,
                                "is_cancelled" => $xdoc["REPLACEMENT_XDOC_ID"] > 0 ? 1 : 0,
                                "insert_date" => $xdoc["INSERT_TIME"],
                                "confirmed_date" => $xdoc["INSERT_TIME"]
                            ]
                        );
                        $matchWithDesadv = $instanceS->get("DESADV_DELJIT", "DD", [], ["XDOC_DELFOR_DETAIL_ID" => $line["ID"]]);
                        if (empty($matchWithDesadv)) {
                            try {
                                $instanceT->create(
                                    "v2_migration",
                                    [
                                        "order_line_id" => $orderDetailId,
                                        "XDOC_DELFOR_DETAIL_ID" => $line["ID"],
                                        "consignee_identifier" => @$line["DeliveryPointCode"],
                                        "dock_identifier" => !empty($line["UnloadingDockCode"]) ? $line["UnloadingDockCode"] : @$line["DeliveryPointCode"],
                                        "original_delivery_date" => @$line["ForecastPeriodStartDate"],
                                    ]
                                );
                            } catch (InvalidQueryException $e) {
                                $logger->critical("Cannot create v2migration row, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                                $connectionT->rollback();
                                die();
                            }
                        }
                        if (!isset($matchWithDesadv[0])) {
                            $matchWithDesadv = [$matchWithDesadv];
                        }
                        foreach ($matchWithDesadv as $desadv) {
                            if (!empty($desadv["XDOC_DESADV_DETAIL_ID"])) {
                                try {
                                    $instanceT->create(
                                        "v2_migration",
                                        [
                                            "order_line_id" => $orderDetailId,
                                            "XDOC_DESADV_DETAIL_ID" => $desadv["XDOC_DESADV_DETAIL_ID"],
                                            "XDOC_DELFOR_DETAIL_ID" => $line["ID"],
                                            "consignee_identifier" => @$line["DeliveryPointCode"],
                                            "dock_identifier" => !empty($line["UnloadingDockCode"]) ? $line["UnloadingDockCode"] : @$line["DeliveryPointCode"],
                                            "original_delivery_date" => @$line["ForecastPeriodStartDate"],
                                        ]
                                    );
                                } catch (InvalidQueryException $e) {
                                    $logger->critical("Cannot match with a desadv, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                                    $connectionT->rollback();
                                    die();
                                }
                            }
                        }
                        // $logger->debug("Line created, id: $orderDetailId.");
                    } catch (InvalidQueryException $e) {
                        $logger->critical("Cannot create line, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                        $connectionT->rollback();
                        die();
                    }
                }
                break;
            case "DELJIT":
                $deljitList = $instanceS->get(
                    "XDOC_DELJIT_DETAIL",
                    "XDD",
                    [],
                    ["XDD.XDOC_ID" => $xdoc["ID"]],
                    false,
                    false,
                    [
                        ["name" => ["XD" => "XDOC_DELJIT"], "on" => "XDD.DELJIT_ID = XD.ID", "columns" => ["Snrf", "HorizonStartDate", "HorizonEndDate", "BuyerCode", "SupplierCode", "SellerCode", "ShipToCode"], "type" => Select::JOIN_LEFT],
                        ["name" => ["X" => "XDOC"], "on" => "XD.XDOC_ID = X.ID", "columns" => [], "type" => Select::JOIN_LEFT],
                        ["name" => ["DD" => "DESADV_DELJIT"], "on" => "XDD.ID = DD.XDOC_DELJIT_DETAIL_ID", "columns" => ["XDOC_DESADV_DETAIL_ID"], "type" => Select::JOIN_LEFT],
                        ["name" => ["XDESD" => "XDOC_DESADV_DETAIL"], "on" => "XDESD.ID = DD.XDOC_DESADV_DETAIL_ID", "columns" => [], "type" => Select::JOIN_LEFT],
                        ["name" => ["XDESDX" => "XDOC"], "on" => "XDESD.XDOC_ID = XDESDX.ID", "columns" => ["DesadvStatus" => "STATUS"], "type" => Select::JOIN_LEFT],
                    ]
                );
                if (!isset($deljitList[0])) {
                    $deljitList = [$deljitList];
                }

                try {
                    $orderId = $instanceT->create(
                        "order",
                        [
                            "fk_document_id" => $documentId,
                            "fk_pr_id" => $pr["pr_id"],
                            "order_number" => !empty($deljitList[0]["PurchaseOrderNumber"]) ? $deljitList[0]["PurchaseOrderNumber"] : $xdoc["RELEASE_NUMBER"],
                            "order_date" => $xdoc["ISSUE_DATE"] != "0000-00-00" ? $xdoc["ISSUE_DATE"] : null,
                            "horizon_start_date" => $deljitList[0]["HorizonStartDate"] != "0000-00-00" ? @$deljitList[0]["HorizonStartDate"] : null,
                            "horizon_end_date" => $deljitList[0]["HorizonEndDate"] != "0000-00-00" ? @$deljitList[0]["HorizonEndDate"] : null,
                            "fk_buyer_id" => $buyerInformation["party_id"],
                            "buyer_identifier" => @$deljitList[0]["BuyerCode"],
                            "fk_supplier_id" => $supplierInformation["party_id"],
                            "supplier_identifier" => @$deljitList[0]["SupplierCode"],
                            "fk_seller_id" => $supplierInformation["party_id"],
                            "seller_identifier" => @$deljitList[0]["SellerCode"],
                            "insert_date" => $xdoc["INSERT_TIME"],
                            "is_confirmed" => 1
                        ]
                    );

                    // $logger->debug("Order created, order id: $orderId.");
                } catch (InvalidQueryException $e) {
                    $logger->critical("Cannot create Order, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                    $connectionT->rollback();
                    die();
                }
                $isCompleted = 1;
                foreach ($deljitList as $item) {
                    if ($item["ScheduleQuantity"] != $item["DeliveredQuantity"]) {
                        $isCompleted = 0;
                        break;
                    }
                }
                foreach ($deljitList as $line) {
                    $consignee = $instanceT->get("consignee", "c", [], ["identifier" => str_replace("&amp;", "&", $deljitList[0]["ShipToCode"]), "fk_buyer_id" => $buyerInformation["party_id"]]);
                    if (empty($consignee)) {
                        try {
                            $consigneeId = $instanceT->create(
                                "consignee",
                                [
                                    "fk_buyer_id" => $buyerInformation["party_id"],
                                    "identifier" => str_replace("&amp;", "&", $deljitList[0]["ShipToCode"]),
                                    "name" => str_replace("&amp;", "&", $deljitList[0]["ShipToCode"]),
                                ]
                            );
                            $consignee["consignee_id"] = $consigneeId;
                            // $logger->debug("Consignee created, consignee id: $consigneeId.");
                        } catch (InvalidQueryException $e) {
                            $logger->critical("Cannot create consignee, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                            $connectionT->rollback();
                            die();
                        }
                    }
                    if (!empty($deljitList["UnloadingDockCode"])) {
                        $dock = $instanceT->get("dock", "d", [], ["identifier" => str_replace("&amp;", "&", $line["UnloadingDockCode"]), "fk_buyer_id" => $buyerInformation["party_id"]]);
                        if (empty($dock) and !empty($line["UnloadingDockCode"]) and strlen($line["UnloadingDockCode"])) {
                            try {
                                $dockId = $instanceT->create(
                                    "dock",
                                    [
                                        "fk_buyer_id" => $buyerInformation["party_id"],
                                        "fk_consignee_id" => $consignee["consignee_id"],
                                        "identifier" => str_replace("&amp;", "&", $line["UnloadingDockCode"]),
                                        "name" => str_replace("&amp;", "&", $line["UnloadingDockCode"]),
                                    ]
                                );
                                $dock["dock_id"] = $dockId;
                                // $logger->debug("Dock created, dock id: $dockId.");
                            } catch (InvalidQueryException $e) {
                                $logger->critical("Cannot create dock, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                                $connectionT->rollback();
                                die();
                            }
                        }
                    }
                    $orderConsignee = $instanceT->get(
                        "order_consignee",
                        "oc",
                        [],
                        [
                            "fk_order_id" => $orderId,
                            "fk_consignee_id" => $consignee["consignee_id"],
                            "fk_dock_id" => $dock["dock_id"] ?? null,
                            "consignee_identifier" => !empty($line["ShipToCode"]) ? $line["ShipToCode"] : $relation["buyerCode"],
                            "dock_identifier" => !empty($line["ShipToCode"]) ? $line["ShipToCode"] : $relation["buyerCode"],
                        ]
                    );
                    if (empty($orderConsignee)) {
                        try {
                            $ocId = $instanceT->create(
                                "order_consignee",
                                [
                                    "fk_order_id" => $orderId,
                                    "fk_consignee_id" => $consignee["consignee_id"],
                                    "fk_dock_id" => $dock["dock_id"] ?? null,
                                    "consignee_identifier" => !empty($line["ShipToCode"]) ? $line["ShipToCode"] : $relation["buyerCode"],
                                    "dock_identifier" => !empty($line["ShipToCode"]) ? $line["ShipToCode"] : $relation["buyerCode"],
                                    "is_completed" => $isCompleted,
                                    "type" => "firm"
                                ]
                            );
                            $orderConsignee["order_consignee_id"] = $ocId;

                            // $logger->debug("OC created, oc id: $ocId.");
                        } catch (InvalidQueryException $e) {
                            $logger->critical("Cannot create order consignee, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                            $connectionT->rollback();
                            die();
                        }
                    }
                    $product = $instanceT->get("product", "p", [], ["identifier" => trim($line["ItemSenderCode"]), "fk_supplier_id" => $supplierInformation["party_id"]]);
                    if (empty($product)) {
                        try {
                            $productId = $instanceT->create(
                                "product",
                                [
                                    "fk_supplier_id" => $supplierInformation["party_id"],
                                    "identifier" => trim($line["ItemSenderCode"]),
                                    "description" => !empty($line["ItemDescription"]) ? $line["ItemDescription"] : $line["ItemSenderCode"]
                                ]
                            );
                            $product["product_id"] = $productId;
                            // $logger->debug("Product created, id: $productId.");
                        } catch (InvalidQueryException $e) {
                            $logger->critical("Cannot create product, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                            $connectionT->rollback();
                            die();
                        }
                    }
                    try {
                        $orderDetailId = $instanceT->create(
                            "order_line",
                            [
                                "fk_order_consignee_id" => $orderConsignee["order_consignee_id"],
                                "fk_order_id" => $orderId,
                                "fk_product_id" => $product["product_id"],
                                "line_number" => $line["SchedulingConditionId"],
                                "release_number" => "0",
                                "identifier" => !empty($line["ItemSenderCode"]) ? $line["ItemSenderCode"] : $line["ItemReceiverCode"],
                                "description" => $line["ItemDescription"] ?? null,
                                "buyer_code" => $line["ItemSenderCode"],
                                "supplier_code" => $line["ItemReceiverCode"] ?? null,
                                "delivery_call_number" => null,
                                "contract_number" => null,
                                "earliest_datetime" => $line["ShipScheduleDate"] == "0000-00-00" ? null : $line["ShipScheduleDate"],
                                "latest_datetime" => $deljitList[0]["HorizonEndDate"] == "0000-00-00" ? null : $deljitList[0]["HorizonEndDate"],
                                "collection_datetime_earliest" => null,
                                "collection_datetime_latest" => null,
                                "last_despatch_datetime" => $line["LastAsnShipmentDate"] == "0000-00-00" ? null : $line["LastAsnShipmentDate"],
                                "order_status" => "firm",
                                "quantity_original" => $line["ScheduleQuantity"],
                                "quantity_confirmed" => $line["ScheduleQuantity"], // !empty($line["approveduserid"]) ? $line["ScheduleQuantity"] : 0,
                                "quantity_packing" => 0,
                                "quantity_packed" => 0,
                                "quantity_to_be_shipped" => 0,
                                "quantity_shipped" => $line["DeliveredQuantity"],
                                "quantity_invoiced" => 0,
                                "unit_quantity" => $line["ScheduleQuantityUom"],
                                "unit_price" => 0,
                                "unit_price_basis" => null,
                                "line_amount" => null,
                                "unit_price_currency" => null,
                                "original_delivery_date" => $line["ShipScheduleDate"] == "0000-00-00" ? null : $line["ShipScheduleDate"],
                                "additional_information" => null,
                                "is_cancelled" => $xdoc["REPLACEMENT_XDOC_ID"] > 0 ? 1 : 0,
                                "insert_date" => $xdoc["INSERT_TIME"],
                                "confirmed_date" => $xdoc["INSERT_TIME"]
                            ]
                        );
                        $matchWithDesadv = $instanceS->get("DESADV_DELJIT", "DD", [], ["XDOC_DELJIT_DETAIL_ID" => $line["ID"]]);
                        if (empty($matchWithDesadv)) {
                            try {
                                $instanceT->create(
                                    "v2_migration",
                                    [
                                        "order_line_id" => $orderDetailId,
                                        "XDOC_DELJIT_DETAIL_ID" => $line["ID"],
                                        "consignee_identifier" => @$line["DeliveryPointCode"],
                                        "dock_identifier" => !empty($line["UnloadingDockCode"]) ? $line["UnloadingDockCode"] : @$line["DeliveryPointCode"],
                                        "original_delivery_date" => @$line["ShipScheduleDate"],
                                    ]
                                );
                            } catch (InvalidQueryException $e) {
                                $logger->critical("Cannot create v2migration row, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                                $connectionT->rollback();
                                die();
                            }
                        }
                        if (!isset($matchWithDesadv[0])) {
                            $matchWithDesadv = [$matchWithDesadv];
                        }
                        foreach ($matchWithDesadv as $desadv) {
                            if (!empty($desadv["XDOC_DESADV_DETAIL_ID"])) {
                                try {
                                    $instanceT->create(
                                        "v2_migration",
                                        [
                                            "order_line_id" => $orderDetailId,
                                            "XDOC_DESADV_DETAIL_ID" => $desadv["XDOC_DESADV_DETAIL_ID"],
                                            "XDOC_DELJIT_DETAIL_ID" => $line["ID"],
                                            "consignee_identifier" => @$line["DeliveryPointCode"],
                                            "dock_identifier" => !empty($line["UnloadingDockCode"]) ? $line["UnloadingDockCode"] : @$line["DeliveryPointCode"],
                                            "original_delivery_date" => @$line["ShipScheduleDate"],
                                        ]
                                    );
                                } catch (InvalidQueryException $e) {
                                    $logger->critical("Cannot match with a desadv, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                                    $connectionT->rollback();
                                    die();
                                }
                            }
                        }
                        // $logger->debug("Line created, id: $orderDetailId.");
                    } catch (InvalidQueryException $e) {
                        $logger->debug("Cannot create order line, id: {$xdoc["ID"]}. Error: {$e->getMessage()}");
                        $connectionT->rollback();
                        die();
                    }
                }
                break;
            case "DESADV":
                $despatchList = $instanceS->get(
                    "XDOC_DESADV_DETAIL",
                    "XDD",
                    [],
                    ["XDD.XDOC_ID" => $xdoc["ID"]],
                    false,
                    false,
                    [
                        ["name" => ["XD" => "XDOC_DESADV"], "on" => "XDD.DESADV_ID = XD.ID", "columns" => ["CarrierName", "ModeOfTransport", "IntermediateConsigneeCode", "FreightBillNumber", "ShipToCode", "ShipmentNumber", "BillOfLadingNumber", "ShipmentDateTime", "EstimatedArrivalDateTime", "TotalGrossWeight", "TotalNetWeight", "TotalGrossWeightUom"], "type" => Select::JOIN_LEFT],
                        ["name" => ["X" => "XDOC"], "on" => "XD.XDOC_ID = X.ID", "columns" => ["STATUS"], "type" => Select::JOIN_LEFT],
                    ]
                );

                if (!isset($despatchList[0])) {
                    $despatchList = [$despatchList];
                }

                $consignee = $instanceT->get("consignee", "c", [], ["identifier" => str_replace("&amp;", "&", $despatchList[0]["ShipToCode"]), "fk_buyer_id" => $buyerInformation["party_id"]]);
                if (empty($consignee)) {
                    try {
                        $consigneeId = $instanceT->create(
                            "consignee",
                            [
                                "fk_buyer_id" => $buyerInformation["party_id"],
                                "identifier" => str_replace("&amp;", "&", $despatchList[0]["ShipToCode"]),
                                "name" => str_replace("&amp;", "&", $despatchList[0]["ShipToCode"]),
                            ]
                        );
                        $consignee["consignee_id"] = $consigneeId;

                        // $logger->debug("Consignee created, consignee id: $consigneeId.");
                    } catch (InvalidQueryException $e) {
                        $logger->critical("Cannot create consignee, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                        $connectionT->rollback();
                        die();
                    }
                }

                $dock = $instanceT->get("dock", "d", [], ["identifier" => str_replace("&amp;", "&", $despatchList[0]["UnloadingArea"]), "fk_buyer_id" => $buyerInformation["party_id"]]);
                if (empty($dock) and !empty($despatchList[0]["UnloadingArea"]) and strlen($despatchList[0]["UnloadingArea"])) {
                    try {
                        $dockId = $instanceT->create(
                            "dock",
                            [
                                "fk_buyer_id" => $buyerInformation["party_id"],
                                "fk_consignee_id" => $consignee["consignee_id"],
                                "identifier" => str_replace("&amp;", "&", $despatchList[0]["UnloadingArea"]),
                                "name" => str_replace("&amp;", "&", $despatchList[0]["UnloadingArea"]),
                            ]
                        );
                        $dock["dock_id"] = $dockId;
                        // $logger->debug("Dock created, dock id: $dockId.");
                    } catch (InvalidQueryException $e) {
                        $logger->critical("Cannot create dock, {$e->getMessage()}; xdoc id: {$xdoc["ID"]}. Aborted.");
                        $connectionT->rollback();
                        die();
                    }
                }
                $shipment = $instanceT->get("shipment", "s", [], ["transport_identifier" => $despatchList[0]["ShipmentNumber"]]);
                if (empty($shipment)) {
                    try {
                        $shipmentId = $instanceT->create(
                            "shipment",
                            [
                                "fk_party_id" => $supplierInformation["party_id"],
                                "fk_carrier_id" => null,
                                "is_shipped" => ($despatchList[0]["STATUS"] & 8) ? 1 : 0,
                                "carrier_name" => $despatchList[0]["CarrierName"],
                                "transport_identifier" => !empty($despatchList[0]["ShipmentNumber"]) ? $despatchList[0]["ShipmentNumber"] : 'Undefined',
                                "transport_identifier_meaning" => 1,
                                "despatch_datetime" => $despatchList[0]["ShipmentDateTime"],
                                "arrival_datetime" => $despatchList[0]["EstimatedArrivalDateTime"],
                                "mode_of_transport" => $despatchList[0]["ModeOfTransport"] == "-1" ? 21 : $despatchList[0]["ModeOfTransport"],
                                "intermediate_consignee_code" => $despatchList[0]["IntermediateConsigneeCode"],
                                "gross_weight" => $despatchList[0]["TotalGrossWeight"] ?? 0,
                                "net_weight" => $despatchList[0]["TotalNetWeight"] ?? 0,
                                "weight_unit" => !empty($despatchList[0]["TotalGrossWeightUom"]) ? $despatchList[0]["TotalGrossWeightUom"] : (!empty($despatchList[0]["TotalNetWeightUom"]) ? $despatchList[0]["TotalNetWeightUom"] : "KG"),
                                "number_of_packages" => null,
                                "shipment_number" => !empty($despatchList[0]["ShipmentNumber"]) ? $despatchList[0]["ShipmentNumber"] : 'Undefined',
                                "port_of_loading" => null,
                                "port_of_discharge" => null,
                                "shipping_mark1" => null,
                                "shipping_mark2" => null,
                                "shipping_mark3" => null,
                                "shipping_mark4" => null,
                                "use_system_despatch_date" => 0,
                                "freight_payment_code" => $despatchList[0]["FreightBillNumber"],
                                "freight_bill_number_details" => $despatchList[0]["FreightBillNumber"],
                                "insert_date" => $xdoc["INSERT_TIME"]
                            ]
                        );
                        $shipment["shipment_id"] = $shipmentId;
                        // $logger->debug("Shipment created, id: $shipmentId");
                    } catch (InvalidQueryException $e) {
                        $logger->critical("Cannot create shipment, {$e->getMessage()}, xdoc id: {$xdoc["ID"]}. Aborted.");
                        $connectionT->rollback();
                        die();
                    }
                }
                try {
                    $despatchId = $instanceT->create(
                        "despatch",
                        [
                            "fk_pr_id" => $pr["pr_id"],
                            "fk_shipment_id" => $shipmentId ?? null,
                            "fk_invoicee_id" => null,
                            "fk_consignee_id" => $consignee["consignee_id"],
                            "fk_dock_id" => $dock["dock_id"],
                            "fk_document_id" => $documentId,
                            "is_shipped" => ($despatchList[0]["STATUS"] & 8) ? 1 : 0,
                            "despatch_number" => !empty($despatchList[0]["ShipmentNumber"]) ? $despatchList[0]["ShipmentNumber"] : 'Undefined',
                            "bill_of_lading_number" => $despatchList[0]["BillOfLadingNumber"],
                            "despatch_date" => $despatchList[0]["ShipmentDateTime"],
                            "arrival_date" => $despatchList[0]["EstimatedArrivalDateTime"],
                            "gross_weight" => $despatchList[0]["TotalGrossWeight"] ?? 0,
                            "net_weight" => $despatchList[0]["TotalNetWeight"] ?? 0,
                            "weight_unit" => $despatchList[0]["TotalGrossWeightUom"] ?? 'KG',
                            "number_of_packages" => null,
                            "insert_date" => $xdoc["INSERT_TIME"]
                        ]
                    );

                    // $logger->debug("Despatch created, despatch id: $despatchId.");
                } catch (InvalidQueryException $e) {
                    $logger->critical("Cannot create despatch, {$e->getMessage()} xdoc id: {$xdoc["ID"]}. Aborted.");
                    $connectionT->rollback();
                    die();
                }
                foreach ($despatchList as $line) {
                    $product = $instanceT->get("product", "p", [], ["identifier" => trim($line["ItemSenderCode"]), "fk_supplier_id" => $supplierInformation["party_id"]]);
                    if (empty($product)) {
                        try {
                            $productId = $instanceT->create(
                                "product",
                                [
                                    "fk_supplier_id" => $supplierInformation["party_id"],
                                    "identifier" => trim($line["ItemSenderCode"]),
                                    "description" => !empty($line["ItemDescription"]) ? $line["ItemDescription"] : $line["ItemSenderCode"]
                                ]
                            );
                            $product["product_id"] = $productId;
                            // $logger->debug("Product created, id: $productId.");
                        } catch (InvalidQueryException $e) {
                            $logger->critical("Cannot create product, {$e->getMessage()}, xdoc id: {$xdoc["ID"]}. Aborted.");
                            continue;
                            // $connectionT->rollback();
                            // die();
                        }
                    }

                    $migratedOrderLine = $instanceT->get("v2_migration", "m", [], ["XDOC_DESADV_DETAIL_ID" => $line["ID"]], 1);
                    try {
                        $dpId = $instanceT->create(
                            "despatch_product",
                            [
                                "fk_despatch_id" => $despatchId,
                                "fk_order_line_id" => $migratedOrderLine["order_line_id"],
                                "fk_product_id" => $product["product_id"],
                                "quantity_despatch" => $line["DispatchQuantity"],
                                "quantity_package_handling" => 1,
                                "quantity_package_packaging" => 1,
                                "unit_quantity" => "PCE",
                                "advice_note_number" => "",
                                "insert_date" => $xdoc["INSERT_TIME"]
                            ]
                        );
                        $instanceT->update("v2_migration", ["despatch_product_id" => $dpId], ["XDOC_DESADV_DETAIL_ID" => $line["ID"]]);
                        // $logger->debug("Despatch product created. id: $dpId");
                    } catch (InvalidQueryException $e) {
                        $logger->critical("Cannot create despatch product, {$e->getMessage()}, xdoc id: {$xdoc["ID"]}, desadvdetail: {$line["ID"]}. Aborted.");
                        continue;
                        // $connectionT->rollback();
                        // die();
                    }

                    $product_packaging = $instanceT->get("product_packaging", "pp", [], ["fk_product_id" => $product["product_id"]], 1);
                    try {
                        $outer = $instanceT->create(
                            "despatch_package",
                            [
                                "fk_despatch_id" => $despatchId,
                                "fk_order_line_id" => $migratedOrderLine["order_line_id"],
                                "fk_product_packaging_id" => $product_packaging["product_packaging_id"],
                                "type" => "1",
                                "is_full" => "0",
                                "quantity_package" => 1,
                                "quantity_part" => $line["DispatchQuantity"],
                                "gross_weight" => 1,
                                "net_weight" => 1,
                                "weight_unit" => "KG",
                            ]
                        );
                        $instanceT->create(
                            "despatch_package",
                            [
                                "fk_despatch_id" => $despatchId,
                                "fk_order_line_id" => $migratedOrderLine["order_line_id"],
                                "fk_despatch_package_id" => $outer,
                                "fk_product_packaging_id" => $product_packaging["product_packaging_id"],
                                "type" => "2",
                                "is_full" => "0",
                                "quantity_package" => 1,
                                "quantity_part" => $line["DispatchQuantity"],
                                "gross_weight" => 1,
                                "net_weight" => 1,
                                "weight_unit" => "KG",
                            ]
                        );
                        $outer = null;
                    } catch (InvalidQueryException $e) {
                        $logger->critical("Cannot create despatch package, {$e->getMessage()}, xdoc id: {$xdoc["ID"]}, desadvdetail. Aborted.");
                        $connectionT->rollback();
                        die();
                    }
                }
                break;
        }
    }
}

$suppliers = [];
$buyer = null;
foreach ($relations as $relation) {
    if (in_array($relation["XDOC_TYPE_ID"], ["1","2"])) {
        $suppliers[] = $relation["receiverId"];
        $buyer = $relation["senderId"];
    }
}
$suppliers = array_unique($suppliers);
foreach ($suppliers as $supplier) {
    $logger->info("Calculating cumulatives for SENDER_PARTY: {$buyer} and RECIPIENT: {$supplier}");
    $riWhere = new Where();
    $riWhere->equalTo("SENDER_PARTY_ID", $buyer);
    $riCol = ["ID" => new Expression("MAX(XDD.ID)"),"ItemSenderCode"];
    $receivedIds = $instanceS->get(
        "XDOC_DELJIT_DETAIL",
        "XDD",
        $riCol,
        $riWhere,
        false,
        false,
        [
            ["name" => ["X" => "XDOC"], "on" => "XDD.XDOC_ID = X.ID", "columns" => [], "type" => Select::JOIN_INNER],
            ["name" => ["XD" => "XDOC_DELJIT"], "on" => "XD.XDOC_ID = X.ID", "columns" => ["ShipToCode"], "type" => Select::JOIN_INNER]
        ],
        ["ItemSenderCode", "ShipToCode"]
    );
    $receivedIds = array_column($receivedIds, 'ID');
    $rWhere = new Where();
    $rWhere->in("XDD.ID", $receivedIds);
    $rCol = ["ID","ItemSenderCode", "LastAsnShipmentCumulativeQuantity", "LastReceivedCumulativeQuantity"];
    $rCumulatives = $instanceS->get(
        "XDOC_DELJIT_DETAIL",
        "XDD",
        $rCol,
        $rWhere,
        false,
        false,
        [
            ["name" => ["X" => "XDOC"], "on" => "XDD.XDOC_ID = X.ID", "columns" => [], "type" => Select::JOIN_INNER],
            ["name" => ["XD" => "XDOC_DELJIT"], "on" => "XD.XDOC_ID = X.ID", "columns" => ["ShipToCode"], "type" => Select::JOIN_INNER]
        ]
    );
    foreach ($rCumulatives as $c) {
        $findRelation = $instanceT->get(
            "v2_migration",
            "m",
            [],
            ["XDOC_DELJIT_DETAIL_ID" => $c["ID"]],
            false,
            false,
            [
                ["name" => ["ol" => "order_line"], "on" => "ol.order_line_id = m.order_line_id", "columns" => ["fk_product_id"], "type" => Select::JOIN_LEFT],
                ["name" => ["o" => "order"], "on" => "ol.fk_order_id = o.order_id", "columns" => ["fk_buyer_id"], "type" => Select::JOIN_LEFT],
                ["name" => ["oc" => "order_consignee"], "on" => "ol.fk_order_consignee_id = oc.order_consignee_id", "columns" => ["fk_consignee_id"], "type" => Select::JOIN_LEFT],
            ]
        );
        if (!empty($findRelation)) {
            $instanceT->update(
                "cumulative",
                [
                    "current_acknowledged" => $c["LastReceivedCumulativeQuantity"]
                ],
                [
                    "fk_product_id" => $findRelation["fk_product_id"],
                    "fk_party_id" => $findRelation["fk_buyer_id"],
                    "fk_consignee_id" => $findRelation["fk_consignee_id"]
                ]
            );
        } else {
            $logger->error("Cannot found document relation in v2_migration table. c: received, XDD: {$c["ID"]}");
            $connectionT->rollback();
            die();
        }
    }
    ###
    $siWhere = new Where();
    $siWhere->equalTo("SENDER_PARTY_ID", $buyer)->isNotNull("XDOC_DELJIT_DETAIL_ID");
    $siCol = ["ID" => new Expression("MAX(XDD.ID)"), "ItemSenderCode"];
    $shippedIds = $instanceS->get(
        "XDOC_DELJIT_DETAIL",
        "XDD",
        $siCol,
        $siWhere,
        false,
        false,
        [
            ["name" => ["X" => "XDOC"], "on" => "XDD.XDOC_ID = X.ID", "columns" => [], "type" => Select::JOIN_INNER],
            ["name" => ["XD" => "XDOC_DELJIT"], "on" => "XD.XDOC_ID = X.ID", "columns" => ["ShipToCode"], "type" => Select::JOIN_INNER],
            ["name" => ["DD" => "DESADV_DELJIT"], "on" => "XDD.ID = DD.XDOC_DELJIT_DETAIL_ID", "columns" => ["XDOC_DELJIT_DETAIL_ID"], "type" => Select::JOIN_INNER]
        ],
        ["ItemSenderCode", "ShipToCode"]
    );
    $shippedIds = array_column($shippedIds, 'ID');
    $sWhere = new Where();
    $sWhere->in("XDD.ID", $shippedIds);
    $sCol = ["ID", "ItemSenderCode", "LastAsnShipmentCumulativeQuantity", "LastReceivedCumulativeQuantity"];
    $sCumulatives = $instanceS->get(
        "XDOC_DELJIT_DETAIL",
        "XDD",
        $sCol,
        $sWhere,
        false,
        false,
        [
            ["name" => ["X" => "XDOC"], "on" => "XDD.XDOC_ID = X.ID", "columns" => [], "type" => Select::JOIN_INNER],
            ["name" => ["XD" => "XDOC_DELJIT"], "on" => "XD.XDOC_ID = X.ID", "columns" => ["ShipToCode"], "type" => Select::JOIN_INNER],
            ["name" => ["DD" => "DESADV_DELJIT"], "on" => "XDD.ID = DD.XDOC_DELJIT_DETAIL_ID", "columns" => ["XDOC_DELJIT_DETAIL_ID"], "type" => Select::JOIN_INNER]
        ]
    );
    foreach ($sCumulatives as $c) {
        $findRelation = $instanceT->get(
            "v2_migration",
            "m",
            [],
            ["XDOC_DELJIT_DETAIL_ID" => $c["ID"]],
            false,
            false,
            [
                ["name" => ["ol" => "order_line"], "on" => "ol.order_line_id = m.order_line_id", "columns" => ["fk_product_id"], "type" => Select::JOIN_LEFT],
                ["name" => ["o" => "order"], "on" => "ol.fk_order_id = o.order_id", "columns" => ["fk_buyer_id"], "type" => Select::JOIN_LEFT],
                ["name" => ["oc" => "order_consignee"], "on" => "ol.fk_order_consignee_id = oc.order_consignee_id", "columns" => ["fk_consignee_id"], "type" => Select::JOIN_LEFT],
            ]
        );
        if (!empty($findRelation)) {
            $instanceT->update(
                "cumulative",
                [
                    "current_dispetched" => $c["LastAsnShipmentCumulativeQuantity"],
                ],
                [
                    "fk_product_id" => $findRelation["fk_product_id"],
                    "fk_party_id" => $findRelation["fk_buyer_id"],
                    "fk_consignee_id" => $findRelation["fk_consignee_id"]
                ]
            );
        } else {
            $logger->error("Cannot found document relation in v2_migration table. c: shipped, XDD: {$c["ID"]}");
            $connectionT->rollback();
            die();
        }
    }
}
$connectionT->commit();
