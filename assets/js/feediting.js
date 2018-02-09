function callAllFunctions(){
    var allfunctions=[];
    for ( var i in window) {
        if((typeof window[i]).toString()=="function"){
            if(window[i].name.indexOf('withContainer')==0){
                console.log(window[i].name);
                eval(window[i].name + '()');
            };
        }
    }
}
$(document).ready(function(){
    callAllFunctions();
});

