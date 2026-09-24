import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { parse } from '@vue/compiler-sfc';
import { parse as parseTemplate } from '@vue/compiler-dom';
import ts from 'typescript';
import { activeLocale, setLocale, tr } from '../../resources/js/i18n.ts';
import { checkpointDistanceText } from '../../resources/js/checkpointDistance.ts';
import nl from '../../lang/nl.json' with { type: 'json' };

test('Dutch presentation preserves solo, formats distances and safely interpolates user data', () => {
    setLocale('nl');
    assert.equal(activeLocale.value, 'nl');
    assert.equal(tr('Solo'), 'Solo');
    assert.equal(tr('solo'), 'solo');
    assert.equal(tr('Checkpoint'), 'Controlepunt');
    assert.equal(tr(':name is marked :status.', {name: 'Runner :status', status: 'DNF'}), 'Runner :status staat gemarkeerd als DNF.');
    assert.equal(tr('My custom checkpoint'), 'My custom checkpoint');
    assert.equal(checkpointDistanceText({settings:{swim_km:1,bike_km:35}}, {kind:'split',discipline:'run',distance_km:'0.5'}), 'Lopen: 0,5 km · 36,5 km totaal');
    setLocale('en');
    assert.equal(tr('Checkpoint'), 'Checkpoint');
});

const root = path.resolve(import.meta.dirname, '../..');
const files = (dir: string): string[] => fs.readdirSync(dir, {withFileTypes:true}).flatMap(entry => entry.isDirectory() ? files(path.join(dir, entry.name)) : [path.join(dir, entry.name)]);
const catalogue = nl as Record<string, string>;

test('every static frontend translation has a Dutch entry and matching placeholders', () => {
    const missing = new Set<string>();
    const inspect = (code: string) => {
        const source = ts.createSourceFile('translation.ts', code, ts.ScriptTarget.Latest, true);
        const visit = (node: ts.Node) => {
            if (ts.isCallExpression(node) && ['tr','$t'].includes(node.expression.getText(source))) {
                const key = node.arguments[0];
                if (key && ts.isStringLiteralLike(key) && !Object.hasOwn(catalogue, key.text)) missing.add(key.text);
            }
            ts.forEachChild(node, visit);
        };
        visit(source);
    };
    for (const file of files(path.join(root, 'resources/js'))) {
        if (file.endsWith('.ts')) inspect(fs.readFileSync(file, 'utf8'));
        if (!file.endsWith('.vue')) continue;
        const {descriptor} = parse(fs.readFileSync(file, 'utf8'));
        if (descriptor.scriptSetup) inspect(descriptor.scriptSetup.content);
        if (descriptor.template) {
            const visit = (node: any) => {
                if (node.type === 5) inspect(node.content.content);
                for (const prop of node.props ?? []) if (prop.type === 7 && prop.exp) inspect(prop.exp.content);
                for (const child of node.children ?? []) visit(child);
            };
            visit(parseTemplate(descriptor.template.content));
        }
    }
    assert.deepEqual([...missing], []);
    const tokens = (value: string) => [...new Set(value.match(/:[A-Za-z_][A-Za-z0-9_]*/g) ?? [])].sort();
    for (const [key, value] of Object.entries(catalogue)) assert.deepEqual(tokens(value), tokens(key), key);
});
