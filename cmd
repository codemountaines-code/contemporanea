git config --global user.name "Codemountain"
git config --global user.email "codemountain.es@gmail.com"

cd ~/projects/contemporanea
git init
git add .
git commit -m "Initial commit"

git remote add origin https://github.com/codemountaines-code/contemporanea.git
git branch -M main
git push -u origin main

ls -la ~/.ssh/
ssh-keygen -t rsa -b 4096 -f ~/.ssh/id_rsa -N ""
cat ~/.ssh/id_rsa.pub


ssh -T git@github.com