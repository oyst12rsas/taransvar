"""Execute the actual session-owned mutation SQL with SQLite compatibility functions."""
import pathlib,re,sqlite3,ipaddress
source=(pathlib.Path(__file__).resolve().parents[1]/'html/script/webPokeGatewayLib.php').read_text()
control=source.split('function webPokeLocalControl',1)[1].split('function webPokeGatewaySession',1)[0]
queries=[m[1] for m in re.findall(r"prepare\((['\"])(.*?)\1\)",control,re.S)]
assert len(queries)==3
lookup,update,insert=queries
def compatible(sql):
    return sql.replace("b'0'",'0').replace("b'1'",'1').replace('FOR UPDATE','')
db=sqlite3.connect(':memory:')
db.create_function('INET_ATON',1,lambda ip:int(ipaddress.ip_address(ip)))
db.create_function('NOW',0,lambda:'2026-10-30 12:00:00')
db.execute('CREATE TABLE internalInfections(infectionId INTEGER PRIMARY KEY,ip INTEGER,nettmask INTEGER,unitId INTEGER,status TEXT,handled INTEGER,active INTEGER,lastSeen TEXT,severity INTEGER,why TEXT)')
ip='10.100.0.150'; unit=20; why='DEMO:WEB_POKE:sessionA'
for row in [(1,unit,'Real incident'),(2,unit,'DEMO:WEB_POKE:sessionB'),(3,99,why)]:
    db.execute('INSERT INTO internalInfections(infectionId,ip,unitId,why,active,severity) VALUES(?,INET_ATON(?),?,?,1,3)',(row[0],ip,row[1],row[2]))
assert db.execute(compatible(lookup),(ip,unit,why)).fetchall()==[]
db.execute(compatible(insert),(ip,unit,why))
identity=db.execute(compatible(lookup),(ip,unit,why)).fetchone()[0]
db.execute(compatible(update),(0,identity))
assert db.execute('SELECT active FROM internalInfections WHERE infectionId=?',(identity,)).fetchone()==(0,)
assert db.execute('SELECT infectionId FROM internalInfections WHERE active=1 ORDER BY infectionId').fetchall()==[(1,),(2,),(3,)]
db.execute(compatible(update),(1,identity))
assert db.execute('SELECT COUNT(*) FROM internalInfections').fetchone()==(4,)
assert db.execute('SELECT COUNT(*) FROM internalInfections WHERE active=1').fetchone()==(4,)
print('Session-only mark/reset SQL passed; genuine findings, other sessions and other units preserved.')
