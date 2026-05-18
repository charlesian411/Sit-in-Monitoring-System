import os
with open('admin_dashboard.php', 'r', encoding='utf-8') as f:
    lines = f.readlines()
with open('new_ui_part.txt', 'r', encoding='utf-8') as f:
    new_ui = f.read()
with open('admin_dashboard.php', 'w', encoding='utf-8') as f:
    f.writelines(lines[:553])
    f.write(new_ui)
os.remove('new_ui_part.txt')
os.remove('merge.py')
