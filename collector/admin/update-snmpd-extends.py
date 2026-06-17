import re, sys

snmpd_conf = sys.argv[1]
extends_file = sys.argv[2]

with open(snmpd_conf, 'r') as f:
    content = f.read()

with open(extends_file, 'r') as f:
    extends_lines = f.read()

new_block = "# BEGIN oracle-mon managed -- do not edit manually\n" + extends_lines + "# END oracle-mon managed"

pattern = r'# BEGIN oracle-mon managed.*?# END oracle-mon managed'
if re.search(pattern, content, re.DOTALL):
    content = re.sub(pattern, new_block, content, flags=re.DOTALL)
else:
    content = content.rstrip('\n') + '\n\n' + new_block + '\n'

with open(snmpd_conf, 'w') as f:
    f.write(content)
print("snmpd.conf updated")
