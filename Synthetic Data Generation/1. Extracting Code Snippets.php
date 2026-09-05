<?php
require 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;

class SnippetExtractor extends NodeVisitorAbstract {
    private string $filePath;
    private string $outputDir;

    public function __construct(string $filePath, string $outputDir) {
        $this->filePath = $filePath;
        $this->outputDir = $outputDir;
    }

    public function processFile(array $stmts) {
        $isEntity = strpos($this->filePath, '/Entity/') !== false || strpos($this->filePath, '\Entity\\') !== false;
        $classFound = $this->findClassAndProcess($stmts, $isEntity);

        if (!$classFound) {
            // No class found message removed
        }
    }
    
    private function findClassAndProcess(array $stmts, bool $isEntity): bool {
        $classFound = false;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Class_ || $stmt instanceof Interface_ || $stmt instanceof Trait_) {
                $classFound = true;
                $className = $stmt->name ? $stmt->name->name : 'AnonymousClass';
                
                $nodeType = 'Class';
                if ($stmt instanceof Interface_) {
                    $nodeType = 'Interface';
                } elseif ($stmt instanceof Trait_) {
                    $nodeType = 'Trait';
                }

                // Found message removed

                if ($isEntity) {
                    $this->processEntityClass($stmt, $className);
                } else {
                    $this->processNonEntityClass($stmt, $className);
                }
            } elseif ($stmt instanceof Namespace_) {
                $foundInNamespace = $this->findClassAndProcess($stmt->stmts, $isEntity);
                if ($foundInNamespace) {
                    $classFound = true;
                }
            }
        }
        return $classFound;
    }

    private function processEntityClass(Node $classNode, string $className) {
        $businessLogicStmts = [];
        $businessLogicLineCount = 0;
        
        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof ClassMethod) {
                $methodName = $stmt->name->name;
                if (!str_starts_with($methodName, 'get') && !str_starts_with($methodName, 'set') && !str_starts_with($methodName, 'is')) {
                    $businessLogicStmts[] = $stmt;
                    $businessLogicLineCount += ($stmt->getEndLine() - $stmt->getStartLine() + 1);
                }
            } elseif (!$stmt instanceof Property && !$stmt instanceof TraitUse) {
                $businessLogicStmts[] = $stmt;
                $businessLogicLineCount += ($stmt->getEndLine() - $stmt->getStartLine() + 1);
            }
        }
        // Echo statements removed
        if ($businessLogicLineCount >= 50 && $businessLogicLineCount <= 600) {
            $combinedCode = '';
            foreach ($businessLogicStmts as $stmt) {
                $combinedCode .= $this->extractCode($stmt->getStartLine(), $stmt->getEndLine()) . "\n";
            }
            if (!empty($combinedCode)) {
                $hash = substr(md5($this->filePath . $className . '_combined'), 0, 8);
                $filename = $this->outputDir . "/" . basename($this->filePath) . "_" . $className . "_entity_combined_" . $hash . ".txt";
                $this->saveToDisk($combinedCode, $filename);
            }
        } elseif ($businessLogicLineCount > 600) {
            foreach ($businessLogicStmts as $stmt) {
                if ($stmt instanceof ClassMethod) {
                    $this->saveSnippet($stmt, "class_" . $className . "_entity_method_" . $stmt->name->name);
                }
            }
        } else {
            // Echo statement removed
        }
    }
    
    private function processNonEntityClass(Node $classNode, string $className) {
        $lineCount = $classNode->getEndLine() - $classNode->getStartLine() + 1;
        // Echo statements removed

        if ($lineCount >= 50 && $lineCount <= 600) {
            $this->saveSnippet($classNode, "class_" . $className . "_full");
        } elseif ($lineCount > 600) {
            $callGraph = $this->buildCallGraph($classNode);
            $groups = $this->findConnectedComponents($callGraph);

            // Separate multi-member and single-member groups
            $multiMemberGroups = [];
            $singleMemberGroups = [];
            foreach ($groups as $group) {
                if (count($group) > 1) {
                    $multiMemberGroups[] = $group;
                } else {
                    $singleMemberGroups[] = $group[0];
                }
            }

            // Save multi-member groups individually
            if (!empty($multiMemberGroups)) {
                foreach ($multiMemberGroups as $i => $group) {
                    $combinedCode = '';
                    foreach ($group as $methodName) {
                        $methodNode = $this->findMethodNode($classNode, $methodName);
                        if ($methodNode) {
                            $combinedCode .= $this->extractCode($methodNode->getStartLine(), $methodNode->getEndLine()) . "\n";
                        }
                    }
                    if (!empty($combinedCode)) {
                        $hash = substr(md5($this->filePath . $className . '_group_' . $i), 0, 8);
                        $filename = $this->outputDir . "/" . basename($this->filePath) . "_" . $className . "_group_" . $i . "_" . $hash . ".txt";
                        $this->saveToDisk($combinedCode, $filename);
                    }
                }
            }

            // Combine and save single-member groups until line limit is reached
            if (!empty($singleMemberGroups)) {
                $combinedSingleCode = '';
                $combinedSingleLines = 0;
                $singleGroupCounter = 0;
                
                foreach ($singleMemberGroups as $methodName) {
                    $methodNode = $this->findMethodNode($classNode, $methodName);
                    if ($methodNode) {
                        $methodCode = $this->extractCode($methodNode->getStartLine(), $methodNode->getEndLine()) . "\n";
                        $methodLines = $methodNode->getEndLine() - $methodNode->getStartLine() + 1;
                        
                        // Check if adding this method exceeds the limit
                        if ($combinedSingleLines + $methodLines > 600 && $combinedSingleLines > 0) {
                            $hash = substr(md5($this->filePath . $className . '_single_group_' . $singleGroupCounter), 0, 8);
                            $filename = $this->outputDir . "/" . basename($this->filePath) . "_" . $className . "_single_group_" . $singleGroupCounter . "_" . $hash . ".txt";
                            $this->saveToDisk($combinedSingleCode, $filename);
                            
                            // Reset for the next snippet
                            $combinedSingleCode = '';
                            $combinedSingleLines = 0;
                            $singleGroupCounter++;
                        }
                        
                        // Add the current method to the combined snippet
                        $combinedSingleCode .= $methodCode;
                        $combinedSingleLines += $methodLines;
                    }
                }
                
                // Save any remaining code that didn't reach the line limit
                if (!empty($combinedSingleCode)) {
                    $hash = substr(md5($this->filePath . $className . '_single_group_' . $singleGroupCounter), 0, 8);
                    $filename = $this->outputDir . "/" . basename($this->filePath) . "_" . $className . "_single_group_" . $singleGroupCounter . "_" . $hash . ".txt";
                    $this->saveToDisk($combinedSingleCode, $filename);
                }
            }

        } else {
            // Echo statement removed
        }
    }

    private function saveSnippet(Node $node, string $name) {
        $startLine = $node->getStartLine();
        $endLine = $node->getEndLine();
        $code = $this->extractCode($startLine, $endLine);
        $hash = substr(md5($this->filePath . $name . $startLine), 0, 8);
        $filename = $this->outputDir . "/" . basename($this->filePath) . "_" . $name . "_" . $hash . ".txt";
        $this->saveToDisk($code, $filename);
    }
    
    private function buildCallGraph(Node $classNode): array {
        $callGraph = [];
        foreach ($classNode->getMethods() as $method) {
            $methodName = $method->name->name;
            $callGraph[$methodName] = [];

            $visitor = new class extends NodeVisitorAbstract {
                public $calls = [];
                public function enterNode(Node $node) {
                    if ($node instanceof MethodCall && $node->var instanceof Variable && $node->var->name === 'this') {
                        if ($node->name instanceof Identifier) {
                            $this->calls[] = $node->name->name;
                        }
                    }
                }
            };

            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            if ($method->stmts) {
                $traverser->traverse($method->stmts);
            }

            $callGraph[$methodName] = array_unique($visitor->calls);
        }
        return $callGraph;
    }

    private function findConnectedComponents(array $graph): array {
        $visited = [];
        $components = [];
        foreach ($graph as $node => $neighbors) {
            if (!isset($visited[$node])) {
                $component = [];
                $stack = [$node];
                $visited[$node] = true;
                while (!empty($stack)) {
                    $current = array_pop($stack);
                    $component[] = $current;
                    foreach ($graph[$current] as $neighbor) {
                        if (!isset($visited[$neighbor])) {
                            $visited[$neighbor] = true;
                            $stack[] = $neighbor;
                        }
                    }
                }
                $components[] = $component;
            }
        }
        return $components;
    }

    private function findMethodNode(Node $classNode, string $methodName): ?Node {
        foreach ($classNode->getMethods() as $method) {
            if ($method->name->name === $methodName) {
                return $method;
            }
        }
        return null;
    }

    private function extractCode(int $startLine, int $endLine): string {
        $lines = file($this->filePath);

        $realStartLine = $startLine - 1;
        while ($realStartLine > 0) {
            $lineContent = $lines[$realStartLine - 1] ?? '';
            if (trim($lineContent) === '' || str_starts_with(trim($lineContent), '/*') || str_starts_with(trim($lineContent), '//') || str_starts_with(trim($lineContent), '*')) {
                $realStartLine--;
            } else {
                break;
            }
        }

        $snippetLines = array_slice($lines, $realStartLine, $endLine - $realStartLine + 1);
        return implode("", $snippetLines);
    }

    // New method to add the comment and save the file
    private function saveToDisk(string $code, string $filepath) {
        $comment = "// Snippet from: {$this->filePath}\n\n";
        file_put_contents($filepath, $comment . $code);
    }
}

// ------------------ MAIN SCRIPT ------------------

$inputPath = $argv[1] ?? null;
if (!$inputPath) {
    die("Usage: php extract_script.php <path_to_bundle>\n");
}

// 1. Extract the bundle name from the input path
$pathParts = explode(DIRECTORY_SEPARATOR, $inputPath);
$bundleName = end($pathParts);

$outputDir = __DIR__ . "/snippets_output/" . $bundleName;
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$parser = (new ParserFactory)->createForHostVersion();
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inputPath));

foreach ($rii as $file) {
    if ($file->isDir()) continue;
    if (pathinfo($file->getPathname(), PATHINFO_EXTENSION) !== 'php') continue;
    // Echo statements removed

    try {
        $code = file_get_contents($file->getPathname());
        $stmts = $parser->parse($code);

        $extractor = new SnippetExtractor($file->getPathname(), $outputDir);
        $extractor->processFile($stmts);
    } catch (Error $e) {
        // Echo statements removed
    }
}

echo "Snippets extracted to: $outputDir\n";