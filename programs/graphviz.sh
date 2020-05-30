newName=`date "+%Y%m%d%H%M%S"`
mv $1 $newName.dot
dot $newName.dot -Tjpg -o $newName.jpg
