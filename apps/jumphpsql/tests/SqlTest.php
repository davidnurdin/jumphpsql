<?php

namespace Tests;

use App\Db\Sql;
use PHPUnit\Framework\TestCase;

final class SqlTest extends TestCase
{
    public function testNamesAndValuesAreQuoted(): void
    {
        self::assertSame('`a``b`', Sql::ident('a`b'));
        self::assertSame('`shop`.`orders`', Sql::table('shop', 'orders'));
        self::assertSame('NULL', Sql::value(null));
        self::assertSame("'l\\'été\\n'", Sql::value("l'été\n"));
        self::assertSame('0xff00', Sql::value("\xff\x00"));
        self::assertSame('42', Sql::literal('42', true));
        self::assertSame("'42'", Sql::literal('42', false));
    }

    public function testAScriptIsSplitIntoStatements(): void
    {
        $script = "SELECT 'a;b';\n-- a comment; still a comment\nSELECT 2 /* ; */;\n\nDELIMITER \$\$\nCREATE PROCEDURE p() BEGIN SELECT 1; END\$\$\nDELIMITER ;\nSELECT 3";
        self::assertSame(["SELECT 'a;b'", "-- a comment; still a comment\nSELECT 2 /* ; */", 'CREATE PROCEDURE p() BEGIN SELECT 1; END', 'SELECT 3'],
            Sql::split($script));
    }

    public function testTheStatementUnderTheCursorIsFound(): void
    {
        $script = "SELECT 1;\nSELECT 2;\nSELECT 3;";
        self::assertSame('SELECT 2', Sql::statementAt($script, 13));
        self::assertSame('SELECT 1', Sql::statementAt($script, 0));
    }

    public function testReadsAreToldFromWrites(): void
    {
        self::assertTrue(Sql::isRead('  select * from t'));
        self::assertTrue(Sql::isRead("-- hi\nSHOW TABLES"));
        self::assertFalse(Sql::isRead('UPDATE t SET a = 1'));
    }
}
