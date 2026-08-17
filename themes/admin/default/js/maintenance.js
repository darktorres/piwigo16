function displayResponse(domElem, values, mDivs,  mValues) {

    for (let index = 0; index < domElem.length; index++) {
        domElem[index].html(unit_MB.replace("%s",values[index]))
    }

    for (let index = 0; index < mDivs.length; index++) {
        mDivName = mDivs[index].getAttribute("name");
        mDivs[index].title = unit_MB.replace("%s", mValues[mDivName])
    }

    $(".cache-lastCalculated-value").html(no_time_elapsed);
}

$(document).ready(function () {
    $(".refresh-cache-size").on("click", function () {
        $(this).find(".refresh-icon").addClass("animate-spin");

        return new Promise((res, rej) => {
            jQuery.ajax({
                url: "api/v1/cache-size",
                type: "GET",
                dataType: "json",
                success: function (data) {
                    res();

                    var domElemToRefresh = [$(".cache-size-value"), $(".multiple-pictures-sizes"), $(".multiple-compiledTemplate-sizes")];
                    var domElemValues = [data.cacheSize, data.msizes.all, data.templatesSize];
                    for (let i = 0; i < domElemValues.length; i++) {
                      domElemValues[i] = (domElemValues[i]/1024/1024).toFixed(2);
                    }

                    var multipleSizes = $(".delete-check-container").children(".delete-size-check");
                    var multipleSizesValues = data.msizes
                    for (const [key, value] of Object.entries(multipleSizesValues)) {
                        multipleSizesValues[key] = (multipleSizesValues[key]/1024/1024).toFixed(2);
                    }

                    displayResponse(domElemToRefresh , domElemValues, multipleSizes,  multipleSizesValues);

                    $(".animate-spin").removeClass("animate-spin");
                },
                error: function(message) {
                    rej(message);
                    console.log(message);
                }
            });
        })
    })


    
})