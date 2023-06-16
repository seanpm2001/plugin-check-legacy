<?php

namespace WordPressdotorg\Plugin_Check;

use PhpParser\NodeDumper;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\{Node, NodeFinder};
use WordPressdotorg\Plugin_Check\{Error, Guideline_Violation, Message, Notice, Warning};
use WordPressdotorg\Plugin_Check\Checks\Check_Base;

abstract class parser extends Check_Base {
	private $file = '';
	public $fileRelative = '';
	public $needsGetParents = false;
	public $needsGetSiblings = false;
	private $ready = false;
	public $nodeFinder;
	public $stmts;
	private $log = [];
	public $logErrors = [];
	private $log_longer_location = [];
	private $log_already_shown_lines = [];
	public $prettyPrinter;

	public function load($file){
		$this->log = [];
		if(file_exists($file)){
			$this->file = $file;
			$this->fileRelative = str_replace($this->path, '', $this->file);
			$this->parse_file($this->file);
			$this->prettyPrinter = new \PhpParser\PrettyPrinter\Standard;
			if($this->isReady()) {
				$this->find();
			}
		} else {
			echo "ERROR: File ".$file." can't be read by PHP.\n";
		}
		return null;
	}

	abstract function find();

	private function parse_file($file){
		//Options

		// Activate ability to get parents. Performance will be degraded.
		// Get parents using $node->getAttribute('parent')
		if($this->needsGetParents) {
			$traverser = new NodeTraverser;
			$traverser->addVisitor( new ParentConnectingVisitor );
		}

		if($this->needsGetSiblings) {
			$traverser = new NodeTraverser;
			$traverser->addVisitor( new NodeConnectingVisitor );
		}

		//Parse file.
		$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
		try {
			$code = file_get_contents( $file );
			$this->stmts = $parser->parse($code);
			if($this->needsGetParents || $this->needsGetSiblings) {
				$this->stmts = $traverser->traverse( $this->stmts );
			}
		} catch (\PhpParser\Error $error) {
			echo $this->fileRelative . ": Parse error: {$error->getMessage()}\n";
			return;
		}
		$this->nodeFinder = new NodeFinder;
		$this->ready = true;
	}

	public function isReady(){
		return $this->ready;
	}

	function get_args($args){
		$argsArray = [];
		foreach ($args as $arg){
			$argsArray[] = $this->prettyPrinter->prettyPrint([$arg]);
		}
		return '( '.implode(', ', $argsArray).' )';
	}

	function log_func_call($func_call){
		$func_call->setAttribute('comments', null);
		$this->save_log($func_call->getStartLine(), $this->prettyPrinter->prettyPrint([$func_call]).';');
	}

	function log_namespace($namespace){
		$lineText = 'namespace '.$namespace->name->toCodeString();
		$this->save_log($namespace->getStartLine(), $lineText);
	}

	function log_abstraction_declarations($abstraction){
		if(!empty($abstraction)) {
			foreach ( $abstraction as $abstract ) {
				$type = 'unknown';
				switch ($abstract->getType()){
					case 'Stmt_Class':
						$type = 'class';
						break;
					case 'Stmt_Function':
						$type = 'function';
						break;
					case 'Stmt_Interface':
						$type = 'interface';
						break;
					case 'Stmt_Trait':
						$type = 'trait';
						break;
				}
				$lineText = $type." ".$abstract->name->toString();
				/*if(!empty($abstract->params) && $abstract->getType()=='Stmt_Function'){
					$lineText .= " ".$this->get_args($abstract->params);
				}*/
				$this->save_log($abstract->getStartLine(), $lineText);
			}
		}
	}

	function unfold_echo_expr($expr, $exprElements=[]){
		if(is_a($expr, 'PhpParser\Node\Expr\BinaryOp\Concat')){
			$exprElements = array_merge($this->unfold_echo_expr($expr->left, $exprElements), $exprElements);
			if(!empty($expr->right)){
				$exprElements[] = $expr->right;
			}
		} else {
			$exprElements[] = $expr;
		}
		return $exprElements;
	}

	function has_log($logid='default'){
		if(!empty($this->log[$logid])){
			return true;
		}
		return false;
	}

	function save_log($lineNumber, $text, $logid='default'){
		$logLine = [
			'location' => $this->fileRelative.":".$lineNumber." ",
			'text' => $text,
			'textFormatted' => $text,
			'startLine' => $lineNumber
		];
		if(!isset($this->log_longer_location[$logid])){
			$this->log_longer_location[$logid]=0;
		}
		if(strlen($logLine['location']) > $this->log_longer_location[$logid]){
			$this->log_longer_location[$logid]=strlen($logLine['location']);
		}
		$this->log[$logid][] = $logLine;
	}

	public function save_lines_log($startLineNumber, $endLineNumber='', $logid='default'): int {
		$lineLenght = 0;
		if(empty($this->log_already_shown_lines[$logid])){
			$this->log_already_shown_lines[$logid]=[];
		}
		if(!isset($this->log_already_shown_lines[$logid][$startLineNumber])){
			$lines = $this->getLines($startLineNumber, $endLineNumber);
			$linesString = implode("", $lines);
			$lineLenght = strlen($linesString);
			$this->log_already_shown_lines[$logid][$startLineNumber]=[
				'lineLenght' => $lineLenght
			];
			$this->save_log($startLineNumber, $linesString, $logid);
		} else {
			$lineLenght = $this->log_already_shown_lines[$logid][$startLineNumber]['lineLenght'];
		}
		return $lineLenght;
	}

	public function save_lines_node_detail_log($node, $logid='default'){
		$startLine = $node->getStartLine();

		$lineLenght = $this->save_lines_log($startLine, $node->getEndLine(), $logid);

		$detail = $this->prettyPrinter->prettyPrint( [ $node ] );
		if(strlen($detail) + 20 < $lineLenght) {
			foreach ( $this->log[ $logid ] as $key => $log ) {
				if ( $log['startLine'] === $startLine ) {
					if ( empty( $this->log[ $logid ][ $key ]['detail'] ) ) {
						$this->log[ $logid ][ $key ]['detail'] = [];
					}
					$this->log[ $logid ][ $key ]['detail'][] = $detail;
					break;
				}
			}
		}
	}

	public function getLines($startLineNumber, $endLineNumber=''){
		$file = new \SplFileObject($this->file);
		$lines = [];

		if(empty($endLineNumber)){
			$endLineNumber=$startLineNumber;
		}

		for ($i=1; $i<=$endLineNumber; $i++){
			if($i>=$startLineNumber){
				$lines[] = trim($file->current(), " \t\0\x0B");
			}
			if(!$file->eof()){
				$file->current();
				$file->next();
			} else {
				break;
			}
		}
		if(!empty($lines)){
			$lines[array_key_last($lines)] = str_replace(array("\r", "\n"), '', $lines[array_key_last($lines)]);
		}
		return $lines;
	}

	function show_log($message, $logid='default'){
		if(!empty($this->log[$logid])){
			$text = sprintf(
				'%s File %s',
				"<strong>{$message}</strong>",
				$this->fileRelative
			);

			foreach ($this->log[$logid] as $log){
				$text .= sprintf(
					'<br><br>Line %d: %s',
					$log['startLine'],
					"<code>{$log['text']}</code>"
				);
				if(!empty($log['detail'])){
					foreach ($log['detail'] as $key => $detail){
						$log['detail'][$key] = '<code>'.$detail.'</code>';
					}
					$detail = implode(', ', $log['detail']);
					$text .= sprintf(
						'<br>Check %s',
						$detail
					);
				}
			}
			$this->logErrors[] = new Error(
				'needs_sanitize',
				$text
			);
		}
	}
}

