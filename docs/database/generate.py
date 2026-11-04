import subprocess, json, collections, os
def q(sql, n):
    out=subprocess.run(['psql','-h','127.0.0.1','-U','albahar','-d','albahar','-tA','-F','\t','-c',sql],capture_output=True,text=True,env={**os.environ,'PGPASSWORD':'albahar_local_dev'}).stdout
    return [(l.split('\t')+['']*n)[:n] for l in out.rstrip('\n').split('\n') if l.strip()]
cols=q("select table_name,column_name,data_type,coalesce(character_maximum_length::text,''),is_nullable,coalesce(column_default,'') from information_schema.columns where table_schema='public' order by table_name,ordinal_position", 6)
fks=q("""select tc.table_name,kcu.column_name,ccu.table_name,rc.delete_rule from information_schema.table_constraints tc
 join information_schema.key_column_usage kcu on kcu.constraint_name=tc.constraint_name
 join information_schema.constraint_column_usage ccu on ccu.constraint_name=tc.constraint_name
 join information_schema.referential_constraints rc on rc.constraint_name=tc.constraint_name
 where tc.constraint_type='FOREIGN KEY' order by 1,2""", 4)
idx=q("select tablename,indexname,indexdef from pg_indexes where schemaname='public' order by 1,2", 3)
chk=q("select conrelid::regclass::text, conname, pg_get_constraintdef(oid) from pg_constraint where contype='c' and connamespace='public'::regnamespace order by 1,2", 3)
trg=q("select event_object_table, trigger_name from information_schema.triggers group by 1,2 order by 1", 2)
groups={'Identity & access':['users','user_sessions','user_devices','otp_verifications','roles','permissions','role_permissions','user_roles','personal_access_tokens','password_reset_tokens','sessions','account_deletion_requests','idempotency_keys'],
 'Organization':['branches','branch_employees','branch_hours','branch_holidays','branch_settings','delivery_zones','branch_delivery_zones','delivery_slots','delivery_slot_bookings']}
bytable=collections.OrderedDict()
for t,c,dt,ln,nul,de in cols: bytable.setdefault(t,[]).append((c,dt+(f'({ln})' if ln else ''),nul,de))
fk_by=collections.defaultdict(list)
for t,c,rt,rule in fks: fk_by[t].append((c,rt,rule))
md=['# Data dictionary','','Generated from the live PostgreSQL schema (`docs/database/generate.py`). Regenerate after every migration.','',
    f'**{len(bytable)} tables**, {len(fks)} foreign keys, {len(chk)} CHECK constraints, {len(idx)} indexes.','',
    '## Append-only (immutable) tables','','Protected by the `prevent_ledger_mutation()` trigger: '+', '.join(sorted({t for t,_ in trg})),'']
for t,cs in bytable.items():
    if t in ('migrations','cache','cache_locks','jobs','job_batches','failed_jobs'): continue
    md+=[f'## `{t}`','','| Column | Type | Null | Default |','|---|---|---|---|']
    for c,dt,nul,de in cs: md.append(f'| `{c}` | {dt} | {"yes" if nul=="YES" else "no"} | {de.replace("|","/")[:60]} |')
    if fk_by[t]: md+=['','Foreign keys: '+'; '.join(f'`{c}` → `{rt}` (on delete {rule.lower()})' for c,rt,rule in fk_by[t])]
    cc=[(n,d) for tt,n,d in chk if tt==t]
    if cc: md+=['','Checks: '+'; '.join(f'`{d}`' for n,d in cc)]
    ii=[d for tt,n,d in idx if tt==t and 'pkey' not in n]
    if ii: md+=['','Indexes:','']+[f'- `{d.split(" USING ")[0].replace("CREATE ","")} USING {d.split(" USING ")[1]}`' for d in ii]
    md.append('')
open('/home/user/Al_Bahar_Orders_Managment/docs/database/DATA_DICTIONARY.md','w').write('\n'.join(md))
# Mermaid ER diagram (relationships only).
er=['```mermaid','erDiagram']
for t,c,rt,rule in fks:
    if t==rt: continue
    er.append(f'    {rt} ||--o{{ {t} : "{c}"')
er.append('```')
open('/home/user/Al_Bahar_Orders_Managment/docs/database/ER_DIAGRAM.md','w').write('# Entity–relationship diagram\n\nGenerated from foreign keys (renders on GitHub). One line per relationship: parent `||--o{` child : foreign-key column.\n\n'+'\n'.join(er)+'\n')
print(len(bytable),'tables', len(fks),'fks')
