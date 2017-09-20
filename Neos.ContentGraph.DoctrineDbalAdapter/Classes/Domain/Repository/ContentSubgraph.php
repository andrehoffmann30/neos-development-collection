<?php

namespace Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository;

/*
 * This file is part of the Neos.ContentGraph.DoctrineDbalAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */
use Doctrine\DBAL\Connection;
use Neos\ContentGraph\DoctrineDbalAdapter\Infrastructure\Service\DbalClient;
use Neos\ContentRepository\Domain as ContentRepository;
use Neos\ContentRepository\Domain\Projection\Content as ContentProjection;
use Neos\ContentRepository\Utility;
use Neos\Flow\Annotations as Flow;

/**
 * The content subgraph application repository
 *
 * To be used as a read-only source of nodes
 *
 * @api
 */
final class ContentSubgraph implements ContentProjection\ContentSubgraphInterface
{
    /**
     * @Flow\Inject
     * @var DbalClient
     */
    protected $client;

    /**
     * @Flow\Inject
     * @var ContentRepository\Service\NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var ContentRepository\Repository\WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var ContentRepository\Service\NodeTypeConstraintService
     */
    protected $nodeTypeConstraintService;

    /**
     * @Flow\Inject
     * @var ContentRepository\Repository\NodeDataRepository
     * @todo get rid of this
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var ContentProjection\ContentGraphInterface
     * @todo get rid of this
     */
    protected $contentGraph;

    /**
     * @var ContentRepository\ValueObject\SubgraphIdentifier
     */
    protected $subgraphIdentifier;


    public function __construct(ContentRepository\ValueObject\SubgraphIdentifier $subgraphIdentifier)
    {
        $this->subgraphIdentifier = $subgraphIdentifier;
    }


    public function getIdentifier(): ContentRepository\ValueObject\SubgraphIdentifier
    {
        return $this->identifier;
    }


    /**
     * @param ContentRepository\ValueObject\NodeIdentifier $nodeIdentifier
     * @param ContentRepository\Service\Context|null $context
     * @return ContentRepository\Model\NodeInterface|null
     */
    public function findNodeByIdentifier(ContentRepository\ValueObject\NodeIdentifier $nodeIdentifier, ContentRepository\Service\Context $context = null)
    {
        $nodeData = $this->getDatabaseConnection()->executeQuery(
            'SELECT n.* FROM neos_contentgraph_node n
 INNER JOIN neos_contentgraph_hierarchyedge h ON h.childnodesidentifieringraph = n.identifieringraph
 WHERE n.identifierinsubgraph = :nodeIdentifier
 AND h.subgraphidentifier = :subgraphIdentifier',
            [
                'nodeIdentifier' => $nodeIdentifier,
                'subgraphIdentifier' => $this->identifier
            ]
        )->fetch();

        return $nodeData ? $this->mapRawDataToNode($nodeData, $context) : null;
    }

    /**
     * @param ContentRepository\ValueObject\NodeIdentifier $parentIdentifier
     * @param ContentRepository\ValueObject\NodeTypeConstraints|null $nodeTypeConstraints
     * @param int|null $limit
     * @param int|null $offset
     * @param ContentRepository\Service\Context|null $context
     * @return array
     */
    public function findChildNodes(
        ContentRepository\ValueObject\NodeIdentifier $parentIdentifier,
        ContentRepository\ValueObject\NodeTypeConstraints $nodeTypeConstraints = null,
        int $limit = null,
        int $offset = null,
        ContentRepository\Service\Context $context = null
    ): array {
        $query = 'SELECT c.* FROM neos_contentgraph_node p
 INNER JOIN neos_contentgraph_hierarchyedge h ON h.parentnodesidentifieringraph = p.identifieringraph
 INNER JOIN neos_contentgraph_node c ON h.childnodesidentifieringraph = c.identifieringraph
 WHERE p.identifierinsubgraph = :parentIdentifier
 AND h.subgraphidentifier = :subgraphIdentifier';
        $parameters = [
            'parentIdentifier' => $parentIdentifier,
            'subgraphIdentifier' => $this->identifier
        ];
        if ($nodeTypeConstraints) {
            // @todo apply constraints
        }
        $query .= '
 ORDER BY h.position';
        $result = [];
        foreach ($this->getDatabaseConnection()->executeQuery(
            $query,
            $parameters
        )->fetchAll() as $nodeData) {
            $result[] = $this->mapRawDataToNode($nodeData, $context);
        }

        return $result;
    }

    public function countChildNodes(ContentRepository\ValueObject\NodeIdentifier $parentIdentifier, ContentRepository\ValueObject\NodeTypeConstraints $nodeTypeConstraints = null, ContentRepository\Service\Context $contentContext = null): int
    {
        $query = 'SELECT COUNT(identifieringraph) FROM neos_contentgraph_node p
 INNER JOIN neos_contentgraph_hierarchyedge h ON h.parentnodesidentifieringraph = p.identifieringraph
 INNER JOIN neos_contentgraph_node c ON h.childnodesidentifieringraph = c.identifieringraph
 WHERE p.identifierinsubgraph = :parentIdentifier
 AND h.subgraphidentifier = :subgraphIdentifier';
        $parameters = [
            'parentIdentifier' => $parentIdentifier,
            'subgraphIdentifier' => $this->identifier
        ];

        if ($nodeTypeConstraints) {
            // @todo apply constraints
        }

        return $this->getDatabaseConnection()->executeQuery(
            $query,
            $parameters
        )->fetch();
    }

    /**
     * @param ContentRepository\ValueObject\NodeIdentifier $childIdentifier
     * @param ContentRepository\Service\Context|null $context
     * @return ContentRepository\Model\NodeInterface|null
     */
    public function findParentNode(ContentRepository\ValueObject\NodeIdentifier $childIdentifier, ContentRepository\Service\Context $context = null)
    {
        $nodeData = $this->getDatabaseConnection()->executeQuery(
            'SELECT p.* FROM neos_contentgraph_node p
 INNER JOIN neos_contentgraph_hierarchyedge h ON h.parentnodesidentifieringraph = p.identifieringraph
 INNER JOIN neos_contentgraph_node c ON h.childnodesidentifieringraph = c.identifieringraph
 WHERE c.identifierinsubgraph = :childIdentifier
 AND h.subgraphidentifier = :subgraphIdentifier',
            [
                'childIdentifier' => $childIdentifier,
                'subgraphIdentifier' => $this->identifier
            ]
        )->fetch();

        return $nodeData ? $this->mapRawDataToNode($nodeData, $context) : null;
    }

    /**
     * @param ContentRepository\ValueObject\NodeIdentifier $parentIdentifier
     * @param ContentRepository\Service\Context|null $context
     * @return ContentRepository\Model\NodeInterface|null
     */
    public function findFirstChildNode(ContentRepository\ValueObject\NodeIdentifier $parentIdentifier, ContentRepository\Service\Context $context = null)
    {
        $nodeData = $this->getDatabaseConnection()->executeQuery(
            'SELECT c.* FROM neos_contentgraph_node p
 INNER JOIN neos_contentgraph_hierarchyedge h ON h.parentnodesidentifieringraph = p.identifieringraph
 INNER JOIN neos_contentgraph_node c ON h.childnodesidentifieringraph = c.identifieringraph
 WHERE p.identifierinsubgraph = :parentIdentifier
 AND h.subgraphidentifier = :subgraphIdentifier
 ORDER BY h.position',
            [
                'parentIdentifier' => $parentIdentifier,
                'subgraphIdentifier' => $this->identifier
            ]
        )->fetch();

        return $nodeData ? $this->mapRawDataToNode($nodeData, $context) : null;
    }

    /**
     * @param string $path
     * @param ContentRepository\Service\Context|null $contentContext
     * @return ContentRepository\Model\NodeInterface|null
     */
    public function findNodeByPath(string $path, ContentRepository\Service\Context $contentContext = null)
    {
        $edgeNames = explode('/', trim($path, '/'));
        $currentNode = $this->findRootNode();
        foreach ($edgeNames as $edgeName) {
            $currentNode = $this->findChildNodeAlongPath(ContentRepository\ValueObject\NodeIdentifier::fromString($currentNode->getIdentifier()), $edgeName, $contentContext);
            if (!$currentNode) {
                return null;
            }
        }

        return $currentNode;
    }

    /**
     * @param ContentRepository\ValueObject\NodeIdentifier $parentIdentifier
     * @param string $edgeName
     * @param ContentRepository\Service\Context|null $context
     * @return ContentRepository\Model\NodeInterface|null
     */
    public function findChildNodeAlongPath(ContentRepository\ValueObject\NodeIdentifier $parentIdentifier, string $edgeName, ContentRepository\Service\Context $context = null)
    {
        $nodeData = $this->getDatabaseConnection()->executeQuery(
            'SELECT c.* FROM neos_contentgraph_node p
 INNER JOIN neos_contentgraph_hierarchyedge h ON h.parentnodesidentifieringraph = p.identifieringraph
 INNER JOIN neos_contentgraph_node c ON h.childnodesidentifieringraph = c.identifieringraph
 WHERE p.identifierinsubgraph = :parentIdentifier
 AND h.subgraphidentifier = :subgraphIdentifier
 AND h.name = :edgeName
 ORDER BY h.position',
            [
                'parentIdentifier' => $parentIdentifier,
                'subgraphIdentifier' => $this->identifier,
                'edgeName' => $edgeName
            ]
        )->fetch();

        return $nodeData ? $this->mapRawDataToNode($nodeData, $context) : null;
    }

    /**
     * @param string $nodeTypeName
     * @param ContentRepository\Service\Context|null $context
     * @return array|ContentRepository\Model\NodeInterface[]
     */
    public function findNodesByType(string $nodeTypeName, ContentRepository\Service\Context $context = null): array
    {
        $result = [];
        foreach ($this->getDatabaseConnection()->executeQuery(
            'SELECT n.*, h.name, h.index FROM neos_contentgraph_node n
 INNER JOIN neos_contentgraph_hierarchyedge h ON h.childnodesidentifieringraph = n.identifieringraph
 WHERE n.nodetypename = :nodeTypeName
 AND h.subgraphidentifier = :subgraphIdentifier
 ORDER BY h.position',
            [
                'nodeTypeName' => $nodeTypeName,
                'subgraphIdentifier' => $this->identifier
            ]
        )->fetchAll() as $nodeData) {
            $result[] = $this->mapRawDataToNode($nodeData, $context);
        }

        return $result;
    }

    public function findRootNode(): ContentRepository\Model\NodeInterface
    {
        // TODO: Implement findRootNode() method.
    }


    public function traverse(
        ContentRepository\Model\NodeInterface $parent,
        ContentRepository\ValueObject\NodeTypeConstraints $nodeTypeConstraints = null,
        callable $callback,
        ContentRepository\Service\Context $context = null
    ) {
        $callback($parent);
        foreach ($this->findChildNodes(
            ContentRepository\ValueObject\NodeIdentifier::fromString($parent->getIdentifier()),
            $nodeTypeConstraints,
            null,
            null,
            $context
        ) as $childNode) {
            $this->traverse($childNode, $nodeTypeConstraints, $callback, $context);
        }
    }

    protected function mapRawDataToNode(array $nodeData, ContentRepository\Service\Context $context): ContentRepository\Model\NodeInterface
    {
        $nodeType = $this->nodeTypeManager->getNodeType($nodeData['nodetypename']);
        $className = $nodeType->getNodeInterfaceImplementationClassName();

        $subgraphWasCreatedIn = $this->contentGraph->getSubgraphByIdentityHash($nodeData['subgraphidentifier']);
        $legacyDimensionValues = $subgraphWasCreatedIn->getIdentifier()->getDimensionValueCombination()->toLegacyDimensionArray();
        $query = $this->nodeDataRepository->createQuery();
        $nodeData = $query->matching(
            $query->logicalAnd([
                $query->equals('identifier', $nodeData['identifierinsubgraph']),
                $query->equals('dimensionsHash', Utility::sortDimensionValueArrayAndReturnDimensionsHash($legacyDimensionValues))
            ])
        );

        $node = new $className($nodeData, $context);
        $node->nodeType = $nodeType;
        $node->identifier = new ContentRepository\ValueObject\NodeIdentifier($nodeData['identifierinsubgraph']);
        $node->properties = new ContentProjection\PropertyCollection(json_decode($nodeData['properties'], true));
        $node->name = $nodeData['name'];
        $node->index = $nodeData['index'];
        #@todo fetch workspace from finder using the content stream identifier
        #$node->workspace = $this->workspaceRepository->findByIdentifier($this->contentStreamIdentifier);
        $node->subgraphIdentifier = $nodeData['subgraphidentifier'];

        return $node;
    }

    protected function getDatabaseConnection(): Connection
    {
        return $this->client->getConnection();
    }
}
