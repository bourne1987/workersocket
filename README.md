# workersocket
this is io.socket project

	查看分支：git branch
	创建分支dev：git branch dev ; 创建后提交到远程版本库 git push pb dev
	切换分支：git checkout dev ; 
	创建+切换分支：git checkout -b dev
	合并某分支到当前分支：git merge dev
	删除本地分支，强制删除用-D：git branch -d dev ；提交到远程版本库 git push pb :dev(注意:的空格)

	git init 初始化一个版本库
	git add file.txt 新增一个文件
	git commit -m 'add file.txt' 提交一个文件
	git status 查看状态
	git diff readme.txt 比较区别 
	git log  / git reflog 历史的记录
	跳到某个版本
	git reset --hard 60d30b348d0d4d88df793726fab5b70830c596f2
	git reflog 查看回跳记录
	git checkout -- test.php 文件test.php在没有git add之前，撤销之前的操作，可以使用此命令
	git rm test.php 删除文件test.php 需要commit
	git pull --all / git fetch 从远程版本库上同步数据下来，pull和fetch的区别在于pull会自动合并，但是fetch不会
