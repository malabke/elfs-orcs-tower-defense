<?php

namespace JumPHP\Ide\Tests;

use JumPHP\Ide\Debug\XmlNode;
use PHPUnit\Framework\TestCase;

/** DBGp's XML read without any extension (the IDE's own PHP has no SimpleXML): what Xdebug really sends. */
final class XmlNodeTest extends TestCase
{
    public function testXdebugsInitMessage(): void
    {
        $init = XmlNode::parse('<?xml version="1.0" encoding="iso-8859-1"?>' . "\n"
            . '<init xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug" fileuri="file:///workspace/games/clock/app.php" language="PHP" xdebug:language_version="8.5.12" protocol_version="1.0" appid="12" idekey="jumphp">'
            . '<engine version="3.5.3"><![CDATA[Xdebug]]></engine><author><![CDATA[Derick Rethans]]></author>'
            . '<copyright><![CDATA[Copyright (c) 2002-2025 by Derick Rethans]]></copyright></init>');
        self::assertNotNull($init);
        self::assertSame('init', $init->localName());
        self::assertSame('file:///workspace/games/clock/app.php', $init->attr('fileuri'));
        self::assertSame('8.5.12', $init->attr('xdebug:language_version'));
        self::assertSame('Xdebug', $init->child('engine')?->text);
        self::assertSame('3.5.3', $init->child('engine')?->attr('version'));
    }

    public function testAContextWithNestedPropertiesAndBase64(): void
    {
        $xml = '<?xml version="1.0" encoding="iso-8859-1"?>' . "\n"
            . '<response xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug" command="context_get" transaction_id="7" context="0">'
            . '<property name="$label" fullname="$label" type="string" size="8" encoding="base64"><![CDATA[UExBWUVSIDE=]]></property>'
            . '<property name="$scores" fullname="$scores" type="array" children="1" numchildren="2" page="0" pagesize="200">'
            . '<property name="0" fullname="$scores[0]" type="int"><![CDATA[10]]></property>'
            . '<property name="1" fullname="$scores[1]" type="int"><![CDATA[20]]></property></property>'
            . '<property name="$tag" fullname="$tag" type="string" encoding="base64"><![CDATA[YSA8YiAmIGM+]]></property>'
            . '<property name="$q" fullname="$q[&quot;a&gt;b&quot;]" type="null"></property>'
            . '<property name="$e" fullname="$e" type="uninitialized"/></response>';
        $r = XmlNode::parse($xml);
        self::assertNotNull($r);
        self::assertSame('7', $r->attr('transaction_id'));
        $props = $r->children('property');
        self::assertCount(5, $props);
        self::assertSame('PLAYER 1', base64_decode($props[0]->text));
        self::assertSame(['10', '20'], array_map(fn (XmlNode $p) => $p->text, $props[1]->children('property')));
        self::assertSame('$scores[1]', $props[1]->children('property')[1]->attr('fullname'));
        self::assertSame('a <b & c>', base64_decode($props[2]->text));
        self::assertSame('$q["a>b"]', $props[3]->attr('fullname'), 'entities in attributes');
        self::assertSame('uninitialized', $props[4]->attr('type'), 'a self-closing element');
    }

    public function testAnErrorAndAStack(): void
    {
        $r = XmlNode::parse('<response xmlns="urn:debugger_protocol_v1" command="eval" transaction_id="9"><error code="206"><message><![CDATA[error evaluating code]]></message></error></response>');
        self::assertSame('206', $r?->child('error')?->attr('code'));
        self::assertSame('error evaluating code', $r?->child('error')?->child('message')?->text);
        $s = XmlNode::parse("<response command='stack_get' transaction_id='3'>\n<stack where='App\\Form\\MainForm-&gt;tick' level='0' type='file' filename='file:///workspace/games/clock/src/Form/MainForm.php' lineno='21'></stack>\n<stack where='{main}' level='1' type='file' filename='file:///workspace/games/clock/app.php' lineno='3'/></response>");
        self::assertSame(['App\\Form\\MainForm->tick', '{main}'], array_map(fn (XmlNode $f) => $f->attr('where'), $s?->children('stack') ?? []));
        self::assertSame('21', $s?->children('stack')[0]->attr('lineno'));
    }

    public function testBrokenXmlIsRefused(): void
    {
        self::assertNull(XmlNode::parse('<response><property></response>'));
        self::assertNull(XmlNode::parse('<response attr="x"'));
        self::assertNull(XmlNode::parse('<a><![CDATA[x</a>'));
    }
}
